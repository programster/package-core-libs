<?php

/*
 * Library just for cryptography. E.g. password hashing etc.
 */

namespace Programster\CoreLibs;


class EncryptionLib
{
    /**
     * Converts a raw password into a password hash
     * @param String $rawPassword - the password we wish to hash
     * @return string - the generated hash
     */
    public static function generatePasswordHash(string $rawPassword) : string
    {
        return password_hash($rawPassword, PASSWORD_DEFAULT);
    }


    /**
     * Counterpart to generate_password_hash. This function should be used to
     * verify that the provided password is correct. This just wraps around
     * password_verify (req 5.5) which automatically knows which algo and cost
     * to use as they are burried in the hash.
     * @param string $rawPassword - the raw password that the user entered.
     * @param string $expectedHash - the hash that we are expecting
     *                              (generated from generate_password_hash)
     * @return boolean - true if valid, false if not.
     */
    public static function verifyPassword(string $rawPassword, string $expectedHash) : bool
    {
        $verified = false;

        if (password_verify($rawPassword, $expectedHash))
        {
            $verified = true;
        }

        return $verified;
    }
}