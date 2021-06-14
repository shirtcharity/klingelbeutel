<?php declare(strict_types=1);

namespace ShirtCharity\Klingelbeutel\Core\Checkout\Cart\Donation;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\Translation\TranslatorInterface;

class DonationComboCartProcessor implements CartDataCollectorInterface, CartProcessorInterface
{
    /**
     * Line item type of donation items in a product-donation-combo
     */
    public const DONATION_COMBO_DONATION_LINE_ITEM_TYPE = 'donation_combo_donation';

    /**
     * Postfix of the ID of donation line items in a product-donation-combo
     */
    public const DONATION_COMBO_DONATION_ID_POSTFIX = '_donation';

    /**
     * Postfix of the ID of product line items in a product-donation-combo
     * to differentiate between products which have assigned different donation amounts
     */
    public const DONATION_COMBO_PRODUCT_ID_POSTFIX = '_with_donation_amount_';

    /**
     * Payload key to transfer the selected donation amount in product line items
     */
    public const DONATION_AMOUNT_PAYLOAD_KEY = 'donation_amount';

    /**
     * Payload key to transfer the donation label in product line items
     */
    public const DONATION_LABEL_PAYLOAD_KEY = 'donation_label';

    /**
     * @var QuantityPriceCalculator
     */
    private $calculator;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var EntityRepositoryInterface
     */
    private $taxRepository;

    public function __construct(
        QuantityPriceCalculator $calculator,
        TranslatorInterface $translator,
        SystemConfigService $systemConfigService,
        EntityRepositoryInterface $taxRepository
    ) {
        $this->calculator = $calculator;
        $this->translator = $translator;
        $this->systemConfigService = $systemConfigService;
        $this->taxRepository = $taxRepository;
    }

    public function collect(
        CartDataCollection $data,
        Cart $original,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        $this->processCart($original, $context, false);
    }

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        $this->processCart($toCalculate, $context, true);
    }

    private function processCart(Cart $cart, SalesChannelContext $context, bool $addDonationLineItems): void
    {
        $lineItems = new LineItemCollection();

        foreach ($cart->getLineItems() as $lineItem) {
            // If the line item is NOT a product with a donation just pass it through
            if (!$this->isDonationComboProduct($lineItem)) {
                $lineItems->add($lineItem);

                continue;
            }

            // Include the donation amount into the product line item id,
            // since the customer might add the same product with different donation amounts.
            // Then each product with a different donation amount needs its own id.
            $donationPrice = $this->getChosenDonationPrice($lineItem);
            $lineItem->setId($this->getIdForProductLineItemWithDonation($lineItem, $donationPrice));
            $lineItems->add($lineItem);

            // Create and add a donation line item
            if ($addDonationLineItems) {
                $donationLineItem = $this->createDonationForProduct($lineItem);
                $this->setDonationPrice($donationLineItem, $donationPrice, $context);
                $lineItems->add($donationLineItem);
            }
        }

        $cart->setLineItems($lineItems);
    }

    private function isDonationComboProduct(LineItem $productLineItem): bool
    {
        return $productLineItem->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE
            && $productLineItem->hasPayloadValue(self::DONATION_AMOUNT_PAYLOAD_KEY);
    }

    private function getChosenDonationPrice(LineItem $productLineItem): float
    {
        return $productLineItem->getPayloadValue(self::DONATION_AMOUNT_PAYLOAD_KEY) ?? 0;
    }

    private function getIdForProductLineItemWithDonation(LineItem $productLineItem, float $amount): string
    {
        $currentId = $productLineItem->getId();
        if (\mb_strpos($currentId, self::DONATION_COMBO_PRODUCT_ID_POSTFIX) !== false) {
            return $currentId;
        }

        return $currentId . self::DONATION_COMBO_PRODUCT_ID_POSTFIX . $amount;
    }

    private function createDonationForProduct(LineItem $productLineItem): LineItem
    {
        $donationLineItem = new LineItem(
            $productLineItem->getId() . self::DONATION_COMBO_DONATION_ID_POSTFIX,
            self::DONATION_COMBO_DONATION_LINE_ITEM_TYPE,
            $productLineItem->getReferencedId(),
            $productLineItem->getQuantity()
        );

        $donationLineItem->setLabel($this->getDonationLabel($productLineItem));
        $donationLineItem->setGood(false);
        $donationLineItem->setStackable(false);
        $donationLineItem->setRemovable(false);

        return $donationLineItem;
    }

    private function getDonationLabel(LineItem $productLineItem): string
    {
        return $productLineItem->getPayloadValue(self::DONATION_LABEL_PAYLOAD_KEY)
            ?? $this->translator->trans('shirtcharity.klingelbeutel.donation');
    }

    private function setDonationPrice(LineItem $donationLineItem, float $price, SalesChannelContext $context): void
    {
        $donationPriceDefinition = new QuantityPriceDefinition(
            $price,
            $this->getDonationTaxRuleCollection($context),
            $donationLineItem->getQuantity()
        );
        $donationLineItem->setPriceDefinition($donationPriceDefinition);
        $donationLineItem->setPrice(
            $this->calculator->calculate($donationPriceDefinition, $context)
        );
    }

    private function getDonationTaxRuleCollection(SalesChannelContext $context): TaxRuleCollection
    {
        $taxRuleId = $this->systemConfigService->get('Klingelbeutel.config.donationTax');
        if (empty($taxRuleId)) {
            return new TaxRuleCollection([new TaxRule(0.0)]);
        }

        $tax = $this->taxRepository
            ->search(new Criteria([$taxRuleId]), $context->getContext())
            ->getEntities()
            ->first();

        return new TaxRuleCollection([new TaxRule($tax->getTaxRate())]);
    }
}
