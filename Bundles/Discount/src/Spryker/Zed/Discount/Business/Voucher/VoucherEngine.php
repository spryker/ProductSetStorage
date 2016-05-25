<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Discount\Business\Voucher;

use Generated\Shared\Transfer\DiscountVoucherTransfer;
use Generated\Shared\Transfer\VoucherCreateInfoTransfer;
use Orm\Zed\Discount\Persistence\SpyDiscountVoucher;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Spryker\Shared\Discount\DiscountConstants;
use Spryker\Zed\Discount\Dependency\Facade\DiscountToMessengerInterface;
use Spryker\Zed\Discount\DiscountConfig;
use Spryker\Zed\Discount\Persistence\DiscountQueryContainerInterface;

/**
 * Class VoucherEngine
 */
class VoucherEngine
{

    /**
     * @var int
     */
    protected $remainingCodesToGenerate = 0;

    /**
     * @var \Spryker\Zed\Discount\DiscountConfig
     */
    protected $discountConfig;

    /**
     * @var \Spryker\Zed\Discount\Persistence\DiscountQueryContainerInterface
     */
    protected $queryContainer;

    /**
     * @var \Spryker\Zed\Discount\Dependency\Facade\DiscountToMessengerInterface
     */
    protected $messengerFacade;

    /**
     * @var \Propel\Runtime\Connection\ConnectionInterface
     */
    protected $connection;

    /**
     * @param \Spryker\Zed\Discount\DiscountConfig $discountConfig
     * @param \Spryker\Zed\Discount\Persistence\DiscountQueryContainerInterface $queryContainer
     * @param \Spryker\Zed\Discount\Dependency\Facade\DiscountToMessengerInterface $messengerFacade
     * @param \Propel\Runtime\Connection\ConnectionInterface $connection
     */
    public function __construct(
        DiscountConfig $discountConfig,
        DiscountQueryContainerInterface $queryContainer,
        DiscountToMessengerInterface $messengerFacade,
        ConnectionInterface $connection
    ) {
        $this->discountConfig = $discountConfig;
        $this->queryContainer = $queryContainer;
        $this->messengerFacade = $messengerFacade;
        $this->connection = $connection;
    }

    /**
     * @param \Generated\Shared\Transfer\DiscountVoucherTransfer $discountVoucherTransfer
     *
     * @return \Generated\Shared\Transfer\VoucherCreateInfoTransfer
     */
    public function createVoucherCodes(DiscountVoucherTransfer $discountVoucherTransfer)
    {
        $nextVoucherBatchValue = $this->getNextBatchValueForVouchers($discountVoucherTransfer);
        $discountVoucherTransfer->setVoucherBatch($nextVoucherBatchValue);

        return $this->saveBatchVoucherCodes($discountVoucherTransfer);
    }

    /**
     * @param DiscountVoucherTransfer $discountVoucherTransfer
     *
     * @return \Generated\Shared\Transfer\VoucherCreateInfoTransfer
     */
    protected function saveBatchVoucherCodes(DiscountVoucherTransfer $discountVoucherTransfer)
    {
        $this->connection->beginTransaction();
        $voucherCodesAreValid = $this->generateAndSaveVoucherCodes(
            $discountVoucherTransfer,
            $discountVoucherTransfer->getQuantity()
        );

        return $this->acceptVoucherCodesTransaction($voucherCodesAreValid);
    }

    /**
     * @param \Generated\Shared\Transfer\VoucherCreateInfoTransfer $voucherCreateInfoInterface
     *
     * @return \Generated\Shared\Transfer\VoucherCreateInfoTransfer
     */
    protected function acceptVoucherCodesTransaction(VoucherCreateInfoTransfer $voucherCreateInfoInterface)
    {
        if ($voucherCreateInfoInterface->getType() === DiscountConstants::MESSAGE_TYPE_SUCCESS) {
            $this->connection->commit();

            return $voucherCreateInfoInterface;
        }

        $this->connection->rollBack();

        return $voucherCreateInfoInterface;
    }

    /**
     * @param DiscountVoucherTransfer $discountVoucherTransfer
     * @param int $quantity
     *
     * @return \Generated\Shared\Transfer\VoucherCreateInfoTransfer
     */
    protected function generateAndSaveVoucherCodes(DiscountVoucherTransfer $discountVoucherTransfer, $quantity)
    {
        $length = $discountVoucherTransfer->getRandomGeneratedCodeLength();

        $messageCreateInfoTransfer = new VoucherCreateInfoTransfer();

        if (!$length && !$discountVoucherTransfer->getCustomCode()) {
            $messageCreateInfoTransfer->setType(DiscountConstants::MESSAGE_TYPE_ERROR);
            $messageCreateInfoTransfer->setMessage('You must provide length or custom code values.');

            return $messageCreateInfoTransfer;
        }

        $codeCollisions = $this->generateCodes($discountVoucherTransfer, $quantity);

        if ($codeCollisions === 0) {
            $messageCreateInfoTransfer->setType(DiscountConstants::MESSAGE_TYPE_SUCCESS);
            $messageCreateInfoTransfer->setMessage('Voucher codes successfully generated.');

            return $messageCreateInfoTransfer;
        }

        if ($codeCollisions === $discountVoucherTransfer->getQuantity()) {
            $messageCreateInfoTransfer->setType(DiscountConstants::MESSAGE_TYPE_ERROR);
            $messageCreateInfoTransfer->setMessage('No available codes to generate.');

            return $messageCreateInfoTransfer;
        }

        if ($codeCollisions === $this->remainingCodesToGenerate) {
            $messageCreateInfoTransfer->setType(DiscountConstants::MESSAGE_TYPE_ERROR);
            $messageCreateInfoTransfer->setMessage('No available codes to generate. Select higher code length.');

            return $messageCreateInfoTransfer;
        }

        $this->remainingCodesToGenerate = $codeCollisions;

        return $this->generateAndSaveVoucherCodes($discountVoucherTransfer, $codeCollisions);
    }

    /**
     * @param DiscountVoucherTransfer $discountVoucherTransfer
     * @param int $quantity
     *
     * @return int
     */
    protected function generateCodes(DiscountVoucherTransfer $discountVoucherTransfer, $quantity)
    {
        $codeCollisions = 0;
        for ($i = 0; $i < $quantity; $i++) {
            $code = $this->getRandomVoucherCode($discountVoucherTransfer->getRandomGeneratedCodeLength());

            if ($discountVoucherTransfer->getCustomCode()) {
                $code = $this->addCustomCodeToGenerated($discountVoucherTransfer, $code);
            }

            if ($this->voucherCodeExists($code) === true) {
                $codeCollisions++;
                continue;
            }

            $discountVoucherTransfer->setCode($code);

            $this->createVoucherCode($discountVoucherTransfer);
        }
        return $codeCollisions;
    }

    /**
     * @param string $voucherCode
     *
     * @return bool
     */
    protected function voucherCodeExists($voucherCode)
    {
        $voucherCodeEntity = $this->queryContainer
            ->queryDiscountVoucher()
            ->findOneByCode($voucherCode);

        return $voucherCodeEntity !== null;
    }

    /**
     * @param \Generated\Shared\Transfer\DiscountVoucherTransfer $discountVoucherTransfer
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return \Orm\Zed\Discount\Persistence\SpyDiscountVoucher
     */
    public function createVoucherCode(DiscountVoucherTransfer $discountVoucherTransfer)
    {
        $voucherEntity = new SpyDiscountVoucher();
        $voucherEntity->fromArray($discountVoucherTransfer->toArray());

        $voucherEntity
            ->setFkDiscountVoucherPool($discountVoucherTransfer->getFkDiscountVoucherPool())
            ->setIsActive(true)
            ->save();

        return $voucherEntity;
    }

    /**
     * @param int $length
     *
     * @return string
     */
    protected function getRandomVoucherCode($length)
    {
        $allowedCharacters = $this->discountConfig->getVoucherCodeCharacters();

        $consonants = $allowedCharacters[DiscountConfig::KEY_VOUCHER_CODE_CONSONANTS];
        $vowels = $allowedCharacters[DiscountConfig::KEY_VOUCHER_CODE_VOWELS];
        $numbers = $allowedCharacters[DiscountConfig::KEY_VOUCHER_CODE_NUMBERS];

        $code = '';

        while (strlen($code) < $length) {
            if (count($consonants)) {
                $code .= $consonants[random_int(0, count($consonants) - 1)];
            }

            if (count($vowels)) {
                $code .= $vowels[random_int(0, count($vowels) - 1)];
            }

            if (count($numbers)) {
                $code .= $numbers[random_int(0, count($numbers) - 1)];
            }
        }

        return substr($code, 0, $length);
    }

    /**
     * @param DiscountVoucherTransfer $discountVoucherTransfer
     * @param string $code
     *
     * @return string
     */
    protected function addCustomCodeToGenerated(DiscountVoucherTransfer $discountVoucherTransfer, $code)
    {
        $customCode = $discountVoucherTransfer->getCustomCode();
        $replacementString = $this->discountConfig->getVoucherPoolTemplateReplacementString();

        if (!$customCode) {
            return $code;
        }

        if (!strstr($customCode, $replacementString)) {
            return $customCode . $code;
        }

        return str_replace($this->discountConfig->getVoucherPoolTemplateReplacementString(), $code, $customCode);
    }

    /**
     * @param \Generated\Shared\Transfer\DiscountVoucherTransfer $discountVoucherTransfer
     *
     * @return int
     */
    protected function getNextBatchValueForVouchers(DiscountVoucherTransfer $discountVoucherTransfer)
    {
        $nextVoucherBatchValue = 0;

        if ($discountVoucherTransfer->getQuantity() < 2) {
            return $nextVoucherBatchValue;
        }

        $highestBatchValueOnVouchers = $this->queryContainer
            ->queryDiscountVoucher()
            ->filterByFkDiscountVoucherPool($discountVoucherTransfer->getFkDiscountVoucherPool())
            ->orderByVoucherBatch(Criteria::DESC)
            ->findOne();

        if ($highestBatchValueOnVouchers === null) {
            return 1;
        }

        return $highestBatchValueOnVouchers->getVoucherBatch() + 1;
    }

}
