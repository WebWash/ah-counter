<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\SpeechUpload;
use App\Entity\Speech;
use App\Speech\RecordingStorage;
use App\Speech\SpeechLifecycle;
use App\Speech\SpeechStillProcessing;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/speeches')]
final class SpeechController extends AbstractController
{
    public const string UPLOAD_TOKEN_ID = 'speech-upload';
    public const string DELETE_TOKEN_ID = 'speech-delete';

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

    #[Route('/{id}', name: 'speech_show', requirements: ['id' => Requirement::UUID_V4], methods: ['GET'])]
    public function show(Speech $speech): Response
    {
        return $this->render('speech/show.html.twig', ['speech' => $speech]);
    }

    /**
     * Streams the Recording with its stored type. BinaryFileResponse answers Range requests (206).
     */
    #[Route('/{id}/recording', name: 'speech_recording', requirements: ['id' => Requirement::UUID_V4], methods: ['GET'])]
    public function recording(Speech $speech, RecordingStorage $recordingStorage): BinaryFileResponse
    {
        $response = new BinaryFileResponse($recordingStorage->path($speech), headers: [
            'Content-Type' => $speech->getRecordingMimeType(),
        ], autoEtag: true);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $speech->getRecordingFilename());

        return $response;
    }

    #[Route('/{id}/delete', name: 'speech_delete', requirements: ['id' => Requirement::UUID_V4], methods: ['POST'])]
    public function delete(Request $request, Speech $speech, SpeechLifecycle $lifecycle): Response
    {
        if (!$this->isCsrfTokenValid(self::DELETE_TOKEN_ID, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        try {
            $lifecycle->delete($speech);
        } catch (SpeechStillProcessing $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        return $this->redirectToRoute('app_home');
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
