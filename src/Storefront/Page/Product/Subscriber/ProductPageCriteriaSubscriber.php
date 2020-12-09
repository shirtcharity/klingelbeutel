<?php declare(strict_types=1);

namespace ShirtCharity\Klingelbeutel\Storefront\Page\Product\Subscriber;

use Shopware\Storefront\Page\Product\ProductLoaderCriteriaEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductPageCriteriaSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ProductLoaderCriteriaEvent::class => 'onProductCriteriaLoaded',
        ];
    }

    public function onProductCriteriaLoaded(ProductLoaderCriteriaEvent $event): void
    {
        $event->getCriteria()->addAssociation('charities.logo');
        $event->getCriteria()->addAssociation('charities.translations');
    }
}
