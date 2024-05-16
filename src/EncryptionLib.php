<?php

/*
 * Library just for cryptography. E.g. password hashing etc.
 */

namespace Programster\CoreLibs;


use Programster\CoreLibs\Exceptions\ExceptionMissingExtension;

class EncryptionLib
{
    /**
     * Encrypt a String
     * @param String $textToEncrypt - the text to encrypt
     * @param String $key - the key to encrypt and then decrypt the message.
     * @param string $initializationVector - a 16 character string to initialize the cipher.
     *                                       this way two plaintext messages can result in different
     *                                       encrypted strings. (like a salt in hashing).
     * @return string - the encryptd form of the string
     */
    public static function encrypt(string $textToEncrypt, string $key, string $initializationVector) : string
    {
        if (!extension_loaded('openssl') )
        {
            throw new ExceptionMissingExtension("Your PHP does not have the openssl extension.");
        }

        $cipherMethod = "AES-256-OFB";

        return openssl_encrypt(
            $textToEncrypt,
            $cipherMethod,
            $key,
            OPENSSL_ZERO_PADDING,
            $initializationVector
        );
    }


    /**
     * Decrypt a string that was encrypted with our encrypt method.
     * @param string $encryptedData - the encrypted text.
     * @param string $key - the decryption key/password
     * @param string $initializationVector - a 16 character string to initialize the cipher.
     * @return string - the decrypted string
     */
    public static function decrypt(string $encryptedText, string $key, string $initializationVector) : string
    {
        $cipherMethod = "AES-256-OFB";

        return openssl_decrypt(
            $encryptedText,
            $cipherMethod,
            $key,
            OPENSSL_ZERO_PADDING,
            $initializationVector
        );
    }


    /**
     * Create an initialization vector suitable for our encrypt method.
     * WARNING - you may likely wish to transport/store this string in a base64 encoded format.
     * @return string
     * @throws \Random\RandomException
     */
    public static function createInitializationVector() : string
    {
        return random_bytes(16);
    }


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