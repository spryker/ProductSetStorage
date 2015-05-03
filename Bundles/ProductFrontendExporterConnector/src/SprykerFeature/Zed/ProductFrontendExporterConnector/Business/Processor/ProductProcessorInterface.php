<?php

namespace SprykerFeature\Zed\ProductFrontendExporterConnector\Business\Processor;

use SprykerEngine\Shared\Locale\Dto\LocaleDto;

interface ProductProcessorInterface
{
    /**
     * @param array $products
     * @param LocaleDto $locale
     *
     * @return array
     */
    public function buildProducts(array $products, LocaleDto $locale);
}
