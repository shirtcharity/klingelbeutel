<?php declare(strict_types=1);

namespace ShirtCharity\Klingelbeutel\Core\Checkout\Cart\Donation;

use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class DonationLineItemAddedSubscriber implements EventSubscriberInterface
{
    /**
     * @var RequestStack
     */
    private $requestStack;

    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    public static function getSubscribedEvents()
    {
        return [
            BeforeLineItemAddedEvent::class => 'onLineItemAdded',
        ];
    }

    public function onLineItemAdded(BeforeLineItemAddedEvent $event): void
    {
        if (!$this->requestStack->getCurrentRequest()->get('lineItems')) {
            return;
        }

        $lineItem = $event->getLineItem();
        $amountKey = DonationComboCartProcessor::DONATION_AMOUNT_PAYLOAD_KEY;
        $labelKey = DonationComboCartProcessor::DONATION_LABEL_PAYLOAD_KEY;

        foreach ($this->requestStack->getCurrentRequest()->get('lineItems') as $key => $item) {
            if ($lineItem->getReferencedId() === $key && isset($item[$amountKey])) {
                $lineItem->setPayloadValue($amountKey, (float) $item[$amountKey]);
            }
            if ($lineItem->getReferencedId() === $key && isset($item[$labelKey])) {
                $lineItem->setPayloadValue($labelKey, $item[$labelKey]);
            }
        }
    }
}
