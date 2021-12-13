<?php

namespace Programster\CoreLibs\Testing\Tests;

class TestCsvWalk extends \Programster\CoreLibs\Testing\AbstractTest
{
    public function getDescription(): string
    {
        return "Test that running CsvLib::csvWalk function works as expected.";
    }


    public function run()
    {
        $filepath = __DIR__ . '/../assets/example-csv.csv';
        $this->m_passed = true;

        $callback1 = function(array $row, int $rowIndex) {
            switch ($rowIndex)
            {
                case 0:
                {
                    // should not get here because have headers.
                    $this->m_passed = false;
                }
                break;

                case 1:
                {
                    if (count($row) !== 2)
                    {
                        $this->m_passed = false;
                        break;
                    }

                    if (!array_key_exists('first', $row) || !array_key_exists('second', $row))
                    {
                        $this->m_passed = false;
                        break;
                    }

                    if ($row['first'] !== 'hello' || $row['second'] !== 'world')
                    {
                        $this->m_passed = false;
                        break;
                    }
                }
                break;

                case 2:
                {
                    if (count($row) !== 2)
                    {
                        $this->m_passed = false;
                        break;
                    }

                    if (!array_key_exists('first', $row) || !array_key_exists('second', $row))
                    {
                        $this->m_passed = false;
                        break;
                    }

                    if ($row['first'] !== 'foo' || $row['second'] !== 'bar')
                    {
                        $this->m_passed = false;
                        break;
                    }
                }
                break;

                default:
                {
                    // should not get here.
                    $this->m_passed = false;
                }
            }

        };

        \Programster\CoreLibs\CsvLib::csvWalk($filepath, $callback1);
    }
}
