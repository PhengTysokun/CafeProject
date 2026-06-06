<?php

declare(strict_types=1);

namespace KHQR\Tests;

use KHQR\BakongKHQR;
use KHQR\Exceptions\KHQRException;
use PHPUnit\Framework\TestCase;

class TokenApiTest extends TestCase
{
    private const BACKOFF_FACTOR = 2;

    private const RETRY_ATTEMPTS = 3;

    public function test_expired_token(): void
    {
        $valid = BakongKHQR::isExpiredToken('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjp7ImlkIjoiMWExMTJhNGE2NWZhNGNkNSJ9LCJpYXQiOjE3MjgxNjg1OTAsImV4cCI6MTczNTk0NDU5MH0.TW2cWPrcPTWTuR-Hth_6tXnNLoTjQVBknuyRlMQIluk');
        $this->assertTrue($valid);
    }

    public function test_renew_token_unregistered_email(): void
    {
        for ($i = 0; $i < self::RETRY_ATTEMPTS; $i++) {
            try {
                $response = BakongKHQR::renewToken('nonexistent-account@gmail.com');
                $this->assertEquals(10, $response['errorCode'], 'Unregistered email');

                return;
            } catch (KHQRException $e) {
                if ($e->getCode() === 503 || $e->getCode() === 504 || $e->getCode() === 13 || $e->getCode() === 15) {
                    // Unstable server or server cannot be reached; retry again 3 times
                    $waitTime = self::BACKOFF_FACTOR ** $i;
                    sleep($waitTime);

                    continue;
                }

                $this->fail('[test_renew_token_unregistered_email] Unexpected exception occurred: '.$e->getCode().' - '.$e->getMessage());
            }
        }

        // If we reach here, the test failed to get a valid response
        $this->fail('Test failed after all retry attempts.');
    }

    public function test_renew_token_registered_email(): void
    {
        for ($i = 0; $i < self::RETRY_ATTEMPTS; $i++) {
            try {
                $response = BakongKHQR::renewToken('fidele.fr@hotmail.com');
                $this->assertEquals($response['responseCode'], 0, 'Token has been issued');
                if (! isset($response['data']) || ! is_array($response['data']) || ! isset($response['data']['token'])) {
                    $this->fail('[test_renew_token_registered_email] Unexpected data structure: '.json_encode($response));
                }
                $this->assertNotEmpty($response['data']['token'], 'Renewed token string is not empty');

                return;
            } catch (KHQRException $e) {
                if ($e->getCode() === 503 || $e->getCode() === 504 || $e->getCode() === 13 || $e->getCode() === 15) {
                    // Unstable server or server cannot be reached; retry again 3 times
                    $waitTime = self::BACKOFF_FACTOR ** $i;
                    sleep($waitTime);

                    continue;
                }

                $this->fail('[test_renew_token_registered_email] Unexpected exception occurred: '.$e->getCode().' - '.$e->getMessage());
            }
        }

        // If we reach here, the test failed to get a valid response
        $this->fail('Test failed after all retry attempts.');
    }
}
