<?php declare(strict_types=1);

namespace ShirtCharity\Klingelbeutel\Storefront\Page\Product\Subscriber;

use Shopware\Storefront\Page\Product\ProductPageCriteriaEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductPageCriteriaSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageCriteriaEvent::class => 'onProductCriteriaLoaded',
        ];
    }

    public function onProductCriteriaLoaded(ProductPageCriteriaEvent $event): void
    {
        $event->getCriteria()->addAssociation('charities');
        $event->getCriteria()->addAssociation('charities.logo');
        $event->getCriteria()->addAssociation('charities.translations');
    }
}
