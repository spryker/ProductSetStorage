<?php

/**
 * (c) Spryker Systems GmbH copyright protected
 */

namespace SprykerFeature\Client\Shipment\Service;

use SprykerFeature\Client\Shipment\Service\Zed\ShipmentStub;
use SprykerEngine\Client\Kernel\Service\AbstractServiceDependencyContainer;
use SprykerFeature\Client\Shipment\Service\Zed\ShipmentStubInterface;
use SprykerFeature\Client\Shipment\ShipmentDependencyProvider;

class ShipmentDependencyContainer extends AbstractServiceDependencyContainer
{

    /**
     * @return ShipmentStubInterface
     */
    public function createZedStub()
    {
        $zedStub = $this->getProvidedDependency(ShipmentDependencyProvider::SERVICE_ZED);
        $cartStub = new ShipmentStub($zedStub);

        return $cartStub;
    }

}
