<?php

declare(strict_types=1);

namespace KHQR\Tests;

use KHQR\BakongKHQR;
use KHQR\Exceptions\KHQRException;
use PHPUnit\Framework\TestCase;

class BakongAccountExistenceTest extends TestCase
{
    private function test_bakong_account(array $data)
    {
        try {
            $checkAcc = BakongKHQR::checkBakongAccountWithUrl($data['data']['url'], $data['data']['account']);
            $this->assertEquals($data['errorCode'], $checkAcc->status['errorCode'], $data['statement']);
            $this->assertEquals($data['bakongAccountExists'], $checkAcc->data['bakongAccountExists'], $data['statement']);
        } catch (KHQRException $e) {
            $this->assertEquals($data['errorCode'], $e->getCode(), $data['statement']);
        }
    }

    public function test_bakong_account_exists()
    {
        $testData = [
            'statement' => 'Account exists',
            'data' => [
                'url' => 'https://sit-api-bakong.nbc.gov.kh/v1/check_account_exist',
                'account' => 'dave@devb',
            ],
            'errorCode' => null,
            'bakongAccountExists' => true,
        ];
        self::test_bakong_account($testData);
    }

    public function test_bakong_account_not_exists()
    {
        $testData = [
            'statement' => 'Account not found',
            'data' => [
                'url' => 'https://sit-api-bakong.nbc.gov.kh/v1/check_account_exist',
                'account' => 'dope@devb',
            ],
            'errorCode' => null,
            'bakongAccountExists' => false,
        ];
        self::test_bakong_account($testData);
    }

    public function test_bakong_account_invalid_length()
    {
        $testData = [
            'statement' => 'Invalid account length',
            'data' => [
                'url' => 'https://sit-api-bakong.nbc.gov.kh/v1/check_account_exist',
                'account' => 'dopedopedopedopedopedopedope@devb',
            ],
            'errorCode' => KHQRException::ERRORS[KHQRException::BAKONG_ACCOUNT_ID_LENGTH_INVALID][0],
        ];
        self::test_bakong_account($testData);
    }

    public function test_bakong_account_invalid()
    {
        $testData = [
            'statement' => 'Account is invalid',
            'data' => [
                'url' => 'https://sit-api-bakong.nbc.gov.kh/v1/check_account_exist',
                'account' => 'davedevb',
            ],
            'errorCode' => KHQRException::ERRORS[KHQRException::BAKONG_ACCOUNT_ID_INVALID][0],
        ];
        self::test_bakong_account($testData);
    }

    public function test_bakong_account_invalid_url()
    {
        $testData = [
            'statement' => 'Invalid URL',
            'data' => [
                'url' => 'https://sit-sit-api-bakong.nbc.gov.kh/v1/check_account_exist',
                'account' => 'dave@devb',
            ],
        ];
        try {
            BakongKHQR::checkBakongAccountWithUrl($testData['data']['url'], $testData['data']['account']);
        } catch (KHQRException $e) {
            $this->assertStringContainsString('sit-sit-api-bakong.nbc.gov.kh', $e->getMessage());
        }
    }
}
