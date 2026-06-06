<?php

declare(strict_types=1);

namespace KHQR\Tests;

use KHQR\BakongKHQR;
use KHQR\Exceptions\KHQRException;
use PHPUnit\Framework\TestCase;

class TransactionApiTest extends TestCase
{
    private const BACKOFF_FACTOR = 2;

    private const RETRY_ATTEMPTS = 3;

    private static string $token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjp7ImlkIjoiN2Y0NTBhZTIzYWRhNDE1MCJ9LCJpYXQiOjE3NDAzMTg2MTUsImV4cCI6MTc0ODA5NDYxNX0.emVxFsxcLm-8aZv53Itbm8kNgAgpzddNl_Irf_LNiWU';

    private static string $expiredToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjp7ImlkIjoiMWExMTJhNGE2NWZhNGNkNSJ9LCJpYXQiOjE3MjgxNjg1OTAsImV4cCI6MTczNTk0NDU5MH0.TW2cWPrcPTWTuR-Hth_6tXnNLoTjQVBknuyRlMQIluk';

    private function renew_token(): string
    {
        $response = BakongKHQR::renewToken('jake.p3ralta@gmail.com');
        if (! isset($response['data']) || ! is_array($response['data']) || ! isset($response['data']['token'])) {
            $this->fail('[renew_token] Unexpected data structure: '.json_encode($response));
        }

        return $response['data']['token'];
    }

    public function test_check_transaction_by_md5_with_expired_token(): void
    {
        $bakongKhqr = new BakongKHQR(self::$expiredToken);
        try {
            $bakongKhqr->checkTransactionByMD5('d60f3db96913029a2af979a1662c1e72');
        } catch (KHQRException $e) {
            if ($e->getCode() !== 6) {
                $this->fail('[test_check_transaction_by_md5_with_expired_token] Unexpected exception occurred: '.$e->getCode().' - '.$e->getMessage());
            }

            $this->assertEquals(6, $e->getCode());
        }
    }

    public function test_check_transaction_by_md5(): void
    {
        for ($i = 0; $i < self::RETRY_ATTEMPTS; $i++) {
            try {
                $bakongKhqr = new BakongKHQR(self::$token);
                $response = $bakongKhqr->checkTransactionByMD5('d60f3db96913029a2af979a1662c1e72');
                if ($response['errorCode'] !== 1) {
                    $this->fail('[test_check_transaction_by_md5] Unexpected response: '.json_encode($response));
                }

                $this->assertEquals(1, $response['errorCode']);

                return;
            } catch (KHQRException $e) {
                if ($e->getCode() === 503 || $e->getCode() === 504 || $e->getCode() === 13 || $e->getCode() === 15) {
                    // Unstable server or server cannot be reached; retry again 3 times
                    $waitTime = self::BACKOFF_FACTOR ** $i;
                    sleep($waitTime);

                    continue;
                }

                if ($e->getCode() !== 6) {
                    $this->fail('[test_check_transaction_by_md5] Unexpected exception occurred: '.$e->getCode().' - '.$e->getMessage());
                }

                // Token has expired. Renew token and retry again.
                try {
                    self::$token = self::renew_token();
                } catch (KHQRException $e) {
                    $this->fail('Test failed due to an unexpected exception: '.$e->getMessage());
                }
            }
        }

        // If we reach here, the test failed to get a valid response
        $this->fail('Test failed after all retry attempts.');
    }

    public function test_check_transaction_by_full_hash(): void
    {
        for ($i = 0; $i < self::RETRY_ATTEMPTS; $i++) {
            try {
                $bakongKhqr = new BakongKHQR(self::$token);
                $response = $bakongKhqr->checkTransactionByFullHash('dcd53430d3b3005d9cda36f1fe8dedc3714ccf18f886cf5d090d36fee67ef956');
                if ($response['errorCode'] !== 1) {
                    $this->fail('[test_check_transaction_by_full_hash] Unexpected response: '.json_encode($response));
                }

                $this->assertEquals(1, $response['errorCode']);

                return;
            } catch (KHQRException $e) {
                if ($e->getCode() === 503 || $e->getCode() === 504 || $e->getCode() === 13 || $e->getCode() === 15) {
                    // Unstable server or server cannot be reached; retry again 3 times
                    $waitTime = self::BACKOFF_FACTOR ** $i;
                    sleep($waitTime);

                    continue;
                }

                if ($e->getCode() !== 6) {
                    $this->fail('[test_check_transaction_by_full_hash] Unexpected exception occurred: '.$e->getCode().' - '.$e->getMessage());
                }

                // Token has expired. Renew token and retry again.
                try {
                    self::$token = self::renew_token();
                } catch (KHQRException $e) {
                    $this->fail('Test failed due to an unexpected exception: '.$e->getMessage());
                }
            }
        }

        // If we reach here, the test failed to get a valid response
        $this->fail('Test failed after all retry attempts.');
    }

    public function test_check_transaction_by_short_hash(): void
    {
        for ($i = 0; $i < self::RETRY_ATTEMPTS; $i++) {
            try {
                $bakongKhqr = new BakongKHQR(self::$token);
                $response = $bakongKhqr->checkTransactionByShortHash('8465d722', 1.0, 'USD');
                if ($response['errorCode'] !== 1) {
                    $this->fail('[test_check_transaction_by_short_hash] Unexpected response: '.json_encode($response));
                }

                $this->assertEquals(1, $response['errorCode']);

                return;
            } catch (KHQRException $e) {
                if ($e->getCode() === 503 || $e->getCode() === 504 || $e->getCode() === 13 || $e->getCode() === 15) {
                    // Unstable server or server cannot be reached; retry again 3 times
                    $waitTime = self::BACKOFF_FACTOR ** $i;
                    sleep($waitTime);

                    continue;
                }

                if ($e->getCode() !== 6) {
                    $this->fail('[test_check_transaction_by_short_hash] Unexpected exception occurred: '.$e->getCode().' - '.$e->getMessage());
                }

                // Token has expired. Renew token and retry again.
                try {
                    self::$token = self::renew_token();
                } catch (KHQRException $e) {
                    $this->fail('Test failed due to an unexpected exception: '.$e->getMessage());
                }
            }
        }

        // If we reach here, the test failed to get a valid response
        $this->fail('Test failed after all retry attempts.');
    }

    public function test_check_transaction_by_instruction_ref(): void
    {
        for ($i = 0; $i < self::RETRY_ATTEMPTS; $i++) {
            try {
                $bakongKhqr = new BakongKHQR(self::$token);
                $response = $bakongKhqr->checkTransactionByInstructionReference('00001234');
                if ($response['errorCode'] !== 1) {
                    $this->fail('[test_check_transaction_by_instruction_ref] Unexpected response: '.json_encode($response));
                }

                $this->assertEquals(1, $response['errorCode']);

                return;
            } catch (KHQRException $e) {
                if ($e->getCode() === 503 || $e->getCode() === 504 || $e->getCode() === 13 || $e->getCode() === 15) {
                    // Unstable server or server cannot be reached; retry again 3 times
                    $waitTime = self::BACKOFF_FACTOR ** $i;
                    sleep($waitTime);

                    continue;
                }

                if ($e->getCode() !== 6) {
                    $this->fail('[test_check_transaction_by_instruction_ref] Unexpected exception occurred: '.$e->getCode().' - '.$e->getMessage());
                }

                // Token has expired. Renew token and retry again.
                try {
                    self::$token = self::renew_token();
                } catch (KHQRException $e) {
                    $this->fail('Test failed due to an unexpected exception: '.$e->getMessage());
                }
            }
        }

        // If we reach here, the test failed to get a valid response
        $this->fail('Test failed after all retry attempts.');
    }

    public function test_check_transaction_by_external_ref(): void
    {
        for ($i = 0; $i < self::RETRY_ATTEMPTS; $i++) {
            try {
                $bakongKhqr = new BakongKHQR(self::$token);
                $response = $bakongKhqr->checkTransactionByExternalReference('DEV123456ZTH');
                if ($response['errorCode'] !== 1) {
                    $this->fail('[test_check_transaction_by_external_ref] Unexpected response: '.json_encode($response));
                }

                $this->assertEquals(1, $response['errorCode']);

                return;
            } catch (KHQRException $e) {
                if ($e->getCode() === 503 || $e->getCode() === 504 || $e->getCode() === 13 || $e->getCode() === 15) {
                    // Unstable server or server cannot be reached; retry again 3 times
                    $waitTime = self::BACKOFF_FACTOR ** $i;
                    sleep($waitTime);

                    continue;
                }

                if ($e->getCode() !== 6) {
                    $this->fail('[test_check_transaction_by_external_ref] Unexpected exception occurred: '.$e->getCode().' - '.$e->getMessage());
                }

                // Token has expired. Renew token and retry again.
                try {
                    self::$token = self::renew_token();
                } catch (KHQRException $e) {
                    $this->fail('Test failed due to an unexpected exception: '.$e->getMessage());
                }
            }
        }

        // If we reach here, the test failed to get a valid response
        $this->fail('Test failed after all retry attempts.');
    }

    public function test_check_transaction_by_md5_list(): void
    {
        for ($i = 0; $i < self::RETRY_ATTEMPTS; $i++) {
            try {
                $bakongKhqr = new BakongKHQR(self::$token);
                $md5List = [
                    '0dbe08d3829a8b6b59844e51aa38a4e2',
                    '7b0e5c36486d7155eb3ee94997fe9bfb',
                    'e12b3ecc4c066405ce05cd8cacab884c',
                ];
                $response = $bakongKhqr->checkTransactionByMD5List($md5List);
                $this->assertIsArray($response['data'], '[test_check_transaction_by_md5_list] Response should have data array');
                $notFoundArray = array_filter($response['data'], fn ($item) => is_array($item) && ($item['status'] === 'NOT_FOUND' || $item['status'] === 'SERVICE_FAILED'));
                if (count($notFoundArray) !== count($md5List)) {
                    $this->fail('[test_check_transaction_by_md5_list] Unexpected response: '.json_encode($response));
                }

                $this->assertEquals(count($md5List), count($notFoundArray));

                return;
            } catch (KHQRException $e) {
                if ($e->getCode() === 503 || $e->getCode() === 504 || $e->getCode() === 13 || $e->getCode() === 15) {
                    // Unstable server or server cannot be reached; retry again 3 times
                    $waitTime = self::BACKOFF_FACTOR ** $i;
                    sleep($waitTime);

                    continue;
                }

                if ($e->getCode() !== 6) {
                    $this->fail('[test_check_transaction_by_md5_list] Unexpected exception occurred: '.$e->getCode().' - '.$e->getMessage());
                }

                // Token has expired. Renew token and retry again.
                try {
                    self::$token = self::renew_token();
                } catch (KHQRException $e) {
                    $this->fail('Test failed due to an unexpected exception: '.$e->getMessage());
                }
            }
        }

        // If we reach here, the test failed to get a valid response
        $this->fail('Test failed after all retry attempts.');
    }

    public function test_check_transaction_by_hash_list(): void
    {
        for ($i = 0; $i < self::RETRY_ATTEMPTS; $i++) {
            try {
                $bakongKhqr = new BakongKHQR(self::$token);
                $fullHashList = [
                    'f0ae142842181535e678900bc5be1c3bd48d567ced77410a169fb672792968c8',
                    'd3b42e35d618a42b7506a79564083e6e91d5383b63f8aa2cf2ca7e65d55ec858',
                    '9036688e95cb3d1b621a9a989ebe64629d8c118654cfbc47f4d4991d72fc3b44',
                ];
                $response = $bakongKhqr->checkTransactionByFullHashList($fullHashList);
                $this->assertIsArray($response['data'], '[test_check_transaction_by_hash_list] Response should have data array');
                $notFoundArray = array_filter($response['data'], fn ($item) => is_array($item) && ($item['status'] === 'NOT_FOUND' || $item['status'] === 'SERVICE_FAILED'));
                if (count($notFoundArray) !== count($fullHashList)) {
                    $this->fail('[test_check_transaction_by_hash_list] Unexpected response: '.json_encode($response));
                }

                $this->assertEquals(count($fullHashList), count($notFoundArray));

                return;
            } catch (KHQRException $e) {
                if ($e->getCode() === 503 || $e->getCode() === 504 || $e->getCode() === 13 || $e->getCode() === 15) {
                    // Unstable server or server cannot be reached; retry again 3 times
                    $waitTime = self::BACKOFF_FACTOR ** $i;
                    sleep($waitTime);

                    continue;
                }

                if ($e->getCode() !== 6) {
                    $this->fail('[test_check_transaction_by_hash_list] Unexpected exception occurred: '.$e->getCode().' - '.$e->getMessage());
                }

                // Token has expired. Renew token and retry again.
                try {
                    self::$token = self::renew_token();
                } catch (KHQRException $e) {
                    $this->fail('Test failed due to an unexpected exception: '.$e->getMessage());
                }
            }
        }

        // If we reach here, the test failed to get a valid response
        $this->fail('Test failed after all retry attempts.');
    }
}
