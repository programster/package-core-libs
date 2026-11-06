<?php

/*
 * Exception thrown when values in a CSV file that should be numbers aren't, such as in the columns
 * being compared by CsvLib::diffTolerance(). Where each one is can be got with getErrors().
 */

namespace Programster\CoreLibs\Exceptions;


use Programster\CoreLibs\Models\CsvCellError;

class NonNumericCsvValuesException extends InvalidCsvException
{
    # A list of CsvCellError objects.
    private readonly array $m_errors;


    /**
     * Create the exception.
     * @param string $message - the message for the exception.
     * @param array $errors - a list of CsvCellError objects, one for each value that isn't a
     * number.
     * @param int $code - optionally specify the exception code.
     * @param \Throwable|null $previous - optionally specify the previous exception, if this was
     * caused by another.
     */
    public function __construct(string $message, array $errors, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->m_errors = $errors;
    }


    # Accessors
    # A list of CsvCellError objects, one for each value that isn't a number.
    public function getErrors(): array { return $this->m_errors; }
}
