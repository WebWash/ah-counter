<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Speech;
use App\Speech\SpeechDuration;
use App\Validator\AudioRecording;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The multipart body of POST /speeches (SPEC §7).
 */
final readonly class SpeechUpload
{
    public const string MAX_SIZE = '25M';
    public const string TOO_LARGE_MESSAGE = 'This Recording is too large to upload. The limit is 25 MB.';

    public function __construct(
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Please attach a Recording.'),
            new Assert\File(
                maxSize: self::MAX_SIZE,
                binaryFormat: false,
                maxSizeMessage: self::TOO_LARGE_MESSAGE,
                uploadIniSizeErrorMessage: self::TOO_LARGE_MESSAGE,
                uploadFormSizeErrorMessage: self::TOO_LARGE_MESSAGE,
                uploadErrorMessage: 'The Recording did not upload completely. Please try again.',
            ),
            new AudioRecording(),
        ])]
        public ?UploadedFile $recording = null,

        /** The browser's timer value, in seconds. */
        #[Assert\NotNull(message: 'The length of the Recording is missing. Please record it again.')]
        #[Assert\Range(
            notInRangeMessage: SpeechDuration::OUT_OF_RANGE_MESSAGE,
            min: SpeechDuration::MIN_ACCEPTED_SECONDS,
            max: SpeechDuration::MAX_ACCEPTED_SECONDS,
        )]
        public ?float $clientDuration = null,

        #[Assert\Length(max: Speech::TITLE_MAX_LENGTH, normalizer: 'trim', maxMessage: 'The title can be at most {{ limit }} characters long.')]
        public ?string $title = null,

        #[SerializedName('_token')]
        public string $csrfToken = '',
    ) {
    }
}
