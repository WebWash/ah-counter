<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\SpeechUpload;
use App\Entity\Speech;
use App\Entity\SpeechStatus;
use App\Report\ReportBuilder;
use App\Report\TranscriptMarker;
use App\Speech\RecordingStorage;
use App\Speech\SpeechLifecycle;
use App\Speech\SpeechStillProcessing;
use App\Transcription\Transcript;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[Route('/speeches', requirements: ['id' => Requirement::UUID_V4])]
final class SpeechController extends AbstractController
{
    public const string UPLOAD_TOKEN_ID = 'speech-upload';
    public const string DELETE_TOKEN_ID = 'speech-delete';
    public const string RETRY_TOKEN_ID = 'speech-retry';

    /**
     * Accepts a Recording from the recorder. Validation failures come back as 422 problem+json
     * (with `violations`) from #[MapRequestPayload]; the other refusals below use the same shape.
     */
    #[Route('', name: 'speech_upload', methods: ['POST'])]
    public function upload(
        Request $request,
        SpeechLifecycle $lifecycle,
        #[MapRequestPayload] ?SpeechUpload $upload = null,
    ): JsonResponse {
        // PHP drops the whole body when it exceeds post_max_size, so nothing at all arrives.
        if (null === $upload) {
            return $this->uploadRejected($request->server->getInt('CONTENT_LENGTH') > 0
                ? SpeechUpload::TOO_LARGE_MESSAGE
                : 'Please attach a Recording.');
        }

        if (!$this->isCsrfTokenValid(self::UPLOAD_TOKEN_ID, $upload->csrfToken)) {
            return $this->uploadRejected('Your session has expired. Please refresh the page and record again.');
        }

        \assert(null !== $upload->recording && null !== $upload->clientDuration);
        $speech = $lifecycle->create($upload->recording, $upload->clientDuration, $upload->title);

        return $this->json([
            'id' => $speech->getId()->toRfc4122(),
            'url' => $this->generateUrl('speech_show', ['id' => $speech->getId()]),
        ], Response::HTTP_CREATED);
    }

    /**
     * The processing view, the error with Retry, or, once done, the Report (SPEC §7).
     */
    #[Route('/{id}', name: 'speech_show', methods: ['GET'])]
    public function show(Speech $speech, ReportBuilder $reportBuilder, TranscriptMarker $marker): Response
    {
        $report = null;
        $segments = [];
        $countedFillers = [];

        if (SpeechStatus::Done === $speech->getStatus() && null !== $report = $reportBuilder->buildIfAvailable($speech)) {
            $transcript = $speech->transcript() ?? new Transcript([], $report->durationSeconds);
            $occurrences = $speech->fillerOccurrences() ?? [];
            $segments = $marker->segments($transcript, $occurrences);

            foreach ($occurrences as $occurrence) {
                if ($occurrence->isFiller()) {
                    $countedFillers[] = ['filler' => $occurrence, 'context' => $marker->context($transcript, $occurrence)];
                }
            }
        }

        return $this->render('speech/show.html.twig', [
            'speech' => $speech,
            'steps' => SpeechStatus::steps(),
            'report' => $report,
            'segments' => $segments,
            'counted_fillers' => $countedFillers,
        ]);
    }

    /**
     * Polled by the speech-status controller every 2 s.
     */
    #[Route('/{id}/status', name: 'speech_status', methods: ['GET'])]
    public function status(Speech $speech): JsonResponse
    {
        $response = $this->json([
            'status' => $speech->getStatus()->value,
            'error' => $speech->getError(),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/{id}/retry', name: 'speech_retry', methods: ['POST'])]
    public function retry(Request $request, Speech $speech, SpeechLifecycle $lifecycle): Response
    {
        $this->denyUnlessCsrfTokenValid(self::RETRY_TOKEN_ID, $request);

        if (!$lifecycle->retry($speech)) {
            throw new ConflictHttpException('Only a failed Speech can be retried.');
        }

        return $this->redirectToRoute('speech_show', ['id' => $speech->getId()]);
    }

    /**
     * Streams the Recording with its stored type. BinaryFileResponse answers Range requests (206).
     */
    #[Route('/{id}/recording', name: 'speech_recording', methods: ['GET'])]
    public function recording(Speech $speech, RecordingStorage $recordingStorage): BinaryFileResponse
    {
        $response = new BinaryFileResponse($recordingStorage->path($speech), headers: [
            'Content-Type' => $speech->getRecordingMimeType(),
        ], autoEtag: true);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $speech->getRecordingFilename());

        return $response;
    }

    #[Route('/{id}/delete', name: 'speech_delete', methods: ['POST'])]
    public function delete(Request $request, Speech $speech, SpeechLifecycle $lifecycle): Response
    {
        $this->denyUnlessCsrfTokenValid(self::DELETE_TOKEN_ID, $request);

        try {
            $lifecycle->delete($speech);
        } catch (SpeechStillProcessing $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        return $this->redirectToRoute('app_home');
    }

    /**
     * A plain 403: #[IsCsrfTokenValid] would go through the firewall and answer 401, which is
     * misleading in an app without logins.
     */
    private function denyUnlessCsrfTokenValid(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    private function uploadRejected(string $message): JsonResponse
    {
        return $this->json([
            'title' => 'Validation Failed',
            'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
            'detail' => $message,
            'violations' => [['propertyPath' => '', 'title' => $message]],
        ], Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => 'application/problem+json']);
    }
}
