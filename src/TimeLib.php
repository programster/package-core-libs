<?php

/*
 * A library for all your time/date calculation needs!
 */

namespace Programster\CoreLibs;

use function Safe\file_get_contents;
use function Safe\json_decode;
use function Safe\json_encode;


class TimeLib
{

    /**
     * Fetches the number of days in a given month.
     *
     * @param month - optional integer representing which month of the year.
     *                          e.g. 00 = january
     *                if not specified we assume the current month.
     * @param year - optional integer representing which year. e.g. 2013 and
     *               not 13
     *               if not specified we assume the current year.
     *
     * @return numDays - the number of days in that month/year.
     */
    public static function getDaysInMonth($month='', $year='')
    {
        $firstOfSpecifiedMonth = self::getFirstOfMonth($month, $year);
        $numDays = date("t", $firstOfSpecifiedMonth);
        return $numDays;
    }


    /**
     * Fetches the number of days in the month that the timestamp is in.
     *
     * @param timestamp - the timestamp in the month you want to get the number
     *                    of. If blank thenwe assume the current time.
     * @return int numDays - the number of days in that month/year.
     */
    public static function getDaysInMonthOfTimestamp($timestamp = '')
    {
        if ($timestamp == '')
        {
            $timestamp = time();
        }

        $numDays = date("t", $timestamp);

        return $numDays;
    }


    /**
     * Fetches the timestamp (int) of the first of the specified month.
     *
     * @param month - optional integer representing which month of the year.
     *                e.g. 00 = january
     *                if not specified we assume the current month.
     * @param year - optional integer representing which year.
     *               e.g. 2013 and not 13
     *               if not specified we assume the current year.
     *
     * @return numDays - the number of days in that month/year.
     */
    public static function getFirstOfMonth($month='', $year='')
    {
        $currentTime = time();

        if ($month == '')
        {
            $month = date('m', $currentTime);
        }

        if ($year == '')
        {
            $year = date('Y', $currentTime);
        }

        $firstOfSpecifiedMonth = mktime(0, 0, 0, $month, $day=1, $year);

        return $firstOfSpecifiedMonth;
    }



    /**
     * Fetches the timestamp (int) of the first of the specified year.
     * @param year - optional 4 digit integer representing the year. e.g 2011.
     *               if not specified then we assume the current year.
     * @return timestamp - the timestamp of the first of that year.
     */
    public static function getFirstOfYear($year='')
    {
        if ($year == '')
        {
            $currentTime = time();
            $year = date('Y', $currentTime);
        }

        $timestamp = mktime(0, 0, 0, $month=1, $day=1, $year);

        return $timestamp;
    }


    /**
     * Returns the timestamp at midnight on the specified day. If any parameter
     * is left alone, then we assume todays day/month/ or year.
     *
     * @param day   - the day of the month e.g. 01 - 32
     * @param month - the month of the year e.g .01 - 12
     * @param year  - the year e.g. 2013
     *
     * @return $timestamp - the timestamp of that day at midnight
     */
    public static function getTimestampOfDate($day='', $month='', $year='')
    {
        $currentTime = time();

        if ($day == '')
        {
            $day = date('d', $currentTime);
        }

        if ($month == '')
        {
            $month = date('m', $currentTime);
        }

        if ($year == '')
        {
            $year = date('Y', $currentTime);
        }

        $timestamp = mktime(0, 0, 0, $month, $day, $year);
        return $timestamp;
    }


    /**
     * Rounds a timestamp down to midnight of the day it was taken.
     * @param timestamp - the timestamp to round off.
     * @return roundedTimestamp - the timestamp rounded to midnight of that day.
     */
    public static function roundTimestampToDay($timestamp)
    {
        $day   = date('d', $timestamp);
        $month = date('m', $timestamp);
        $year  = date('Y', $timestamp);

        $roundedTimestamp = mktime(0, 0, 0, $month, $day, $year);

        return $roundedTimestamp;
    }


    /**
     * Rounds a timestamp down to midnight on Monday of time it was taken
     * @param timestamp - the timestamp to round off.
     * @return roundedTimestamp - the rounded timestamp
     */
    public static function roundTimestampToWeek($timestamp)
    {
        $roundedToDay = self::roundTimestampToDay($timestamp);
        $weekDay  = date('w', $roundedToDay); # 1 = Monday
        $daysToSubtract = $weekDay - 1;
        $roundedToWeek = strtotime("-" . $daysToSubtract . " day", $roundedToDay);

        return $roundedToWeek;
    }


    /**
     * Rounds a timestamp down to midnight on the first of the month it was
     * taken
     * @param timestamp - the timestamp to round off.
     * @return roundedTimestamp - the rounded timestamp
     */
    public static function roundTimestampDownToMonth($timestamp)
    {
        $month = date('n', $timestamp);
        $year  = date('Y', $timestamp);

        $roundedTimestamp = mktime(0, 0, 0, $month, $day="01", $year);

        return $roundedTimestamp;
    }


    /**
     * Rounds a timestamp down to midnight on the 1st of January of the year it
     * was taken
     * @param timestamp - the timestamp to round off.
     * @return roundedTimestamp - the rounded timestamp
     */
    public static function roundTimestampToYear($timestamp)
    {
        $year  = date('Y', $timestamp);

        $roundedTimestamp = mktime(0, 0, 0, $month=1, $day=1, $year);

        return $roundedTimestamp;
    }


    /**
     * Calculates the number of days there are between two unix timestamps
     * (seconds since epoch). The times do not need to be specified in any
     * particular order.
     *
     * @param time1 - a unix timestamp
     * @param time2 - a unix timestamp.
     *
     * @return int $numDays - the number of whole days there are between the
     *                        two timestamps.
     */
    public static function calculateDayDiff($time1, $time2)
    {
        $timeDiff = abs($time1 - $time2);
        $secondsInDay = 86400;
        $numDays = floor($timeDiff/$secondsInDay);
        return $numDays;
    }


    /**
     * Given a timestamp, get how long ago it was in human readable form.
     * E.g. 10 seconds
     * 4 minutes
     * 1 hour
     * 456 days
     * @param \DateTime $timestamp - the timestamp we want the difference to
     * @return String - a human readable string to represent how long ago the timestamp was.
     */
    public static function get_human_readble_time_difference(\DateTime $timestamp)
    {
        $resultString = "";
        $now = new \DateTime();

        $diffInSeconds = $now->getTimestamp() - $timestamp->getTimestamp();

        if ($diffInSeconds < 60)
        {
            $resultString = "$diffInSeconds seconds";
        }
        elseif ($diffInSeconds < 3600)
        {
            $diffInMinutes = (int) ($diffInSeconds / 60);
            $remainder = (int) ($diffInSeconds % 60);
            $resultString = "$diffInMinutes mins $remainder secs";
        }
        elseif ($diffInSeconds < 86400)
        {
            $diffInHours = (int) ($diffInSeconds / 3600);
            $remainder = (int) ($diffInSeconds % 3600);
            $minutes = (int)($remainder / 60);
            $resultString = "$diffInHours hours $minutes mins";
        }
        else
        {
            $diffInDays = (int) ($diffInSeconds / 86400);
            $remainder = (int) ($diffInSeconds % 86400);
            $hours = (int)($remainder / 3600);
            $resultString = "$diffInDays days $hours hours";
        }

        return $resultString;
    }
}

