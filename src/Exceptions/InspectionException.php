<?php

namespace Quack\Protocol\Exceptions;

use Quack\Protocol\Error\InspectionErrorKind;
use Quack\Protocol\Error\QuackError;

/** A bounded inspection helper rejected its input. */
final class InspectionException extends QuackError
{
    private function __construct(
        private readonly InspectionErrorKind $kind,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function incomplete(): self
    {
        return new self(InspectionErrorKind::Incomplete, 'incomplete quack message header');
    }

    public static function invalid(): self
    {
        return new self(InspectionErrorKind::Invalid, 'invalid quack message header');
    }

    public static function tooLarge(): self
    {
        return new self(InspectionErrorKind::TooLarge, 'quack metadata exceeds inspection limit');
    }

    public function kind(): InspectionErrorKind
    {
        return $this->kind;
    }
}
