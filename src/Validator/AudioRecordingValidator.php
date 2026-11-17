<?php

declare(strict_types=1);

namespace App\Validator;

use App\Speech\AudioFormat;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class AudioRecordingValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AudioRecording) {
            throw new UnexpectedTypeException($constraint, AudioRecording::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof UploadedFile) {
            throw new UnexpectedValueException($value, UploadedFile::class);
        }

        // The browser's Blob.type arrives as the multipart part's Content-Type.
        if (null === AudioFormat::match($value->getPathname(), $value->getClientMimeType())) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
