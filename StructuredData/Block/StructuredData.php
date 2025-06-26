<?php
namespace FunkySquid\StructuredData\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\Registry;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Cms\Model\PageFactory;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\StoreManagerInterface;

class StructuredData extends Template
{
    protected Registry $registry;
    protected HttpRequest $request;
    protected PageFactory $pageFactory;
    protected $scopeConfig;
    protected $pageConfig;
    protected StoreManagerInterface $storeManager;

    public function __construct(
        Template\Context $context,
        Registry $registry,
        HttpRequest $request,
        PageFactory $pageFactory,
        PageConfig $pageConfig,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->registry = $registry;
        $this->request = $request;
        $this->pageFactory = $pageFactory;
        $this->scopeConfig = $context->getScopeConfig();
        $this->pageConfig = $pageConfig;
        $this->storeManager = $storeManager;
        parent::__construct($context, $data);
    }

    public function getScopeConfig()
    {
        return $this->scopeConfig;
    }

    public function getCurrentEntity()
    {
        $actionName = $this->request->getFullActionName();
        error_log("StructuredData: current action = " . $actionName);

        // Product pages
        $product = $this->registry->registry('current_product');
        if ($product instanceof Product) {
            return $product;
        }

        // CMS pages (including homepage)
        if ($actionName === 'cms_page_view' || $actionName === 'cms_index_index') {
            $pageId = $this->request->getParam('page_id');
            if ($pageId) {
                return $this->pageFactory->create()->load($pageId);
            }

            $homeIdentifier = $this->scopeConfig->getValue(
                'web/default/cms_home_page',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );

            if ($homeIdentifier) {
                return $this->pageFactory->create()->load($homeIdentifier, 'identifier');
            }
        }

        // Contact page (custom detection)
        if ($actionName === 'contact_index_index') {
            $contactPage = new \stdClass();
            $contactPage->type = 'contact_page';
            $contactPage->name = 'Contact Us';
            $contactPage->url = $this->getUrl('contact');
            $contactPage->description = 'Contact us for support, inquiries, or service details.';
            return $contactPage;
        }

        return null;
    }

    public function getJsonData()
    {
        $entity = $this->getCurrentEntity();

        if (!$entity) {
            return '';
        }

        $pageUrl = $this->getUrl('*/*/*', ['_current' => true, '_use_rewrite' => true]);
        $siteName = $this->scopeConfig->getValue('general/store_information/name', \Magento\Store\Model\ScopeInterface::SCOPE_STORE) ?: 'Website';
        $logoUrl = $this->getViewFileUrl('images/logo.svg'); // Update path if needed
        $metaDescription = $this->pageConfig->getDescription() ?: 'Find out more on our website.';

        // Product Schema
        if ($entity instanceof Product) {
            return json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => $entity->getName(),
                'sku' => $entity->getSku(),
                'description' => $entity->getShortDescription(),
                'url' => $entity->getProductUrl(),
                'identifier' => $entity->getId(),
                'image' => $this->getViewFileUrl('Magento_Catalog::images/product/placeholder/image.jpg'),
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $siteName,
                    'logo' => [
                        '@type' => 'ImageObject',
                        'url' => $logoUrl,
                        'width' => 80,
                        'height' => 80
                    ]
                ],
                'mainEntityOfPage' => [
                    '@type' => 'WebPage',
                    '@id' => $entity->getProductUrl()
                ],
                'offers' => [
                    '@type' => 'Offer',
                    'price' => $entity->getFinalPrice(),
                    'priceCurrency' => 'GBP'
                ]
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        // CMS Page Schema
        if ($entity instanceof \Magento\Cms\Model\Page) {
            $homeIdentifier = $this->scopeConfig->getValue(
                'web/default/cms_home_page',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );

            $isHomePage = $entity->getIdentifier() === $homeIdentifier;
            $entityUrl = $isHomePage
                ? $this->getUrl('') // Generates base URL `/`
                : $this->getUrl(null, ['_direct' => $entity->getIdentifier()]);

            return json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'headline' => $entity->getTitle(),
                'name' => $entity->getTitle(),
                'description' => $metaDescription,
                'url' => $entityUrl,
                'identifier' => $entityUrl,
                'image' => [
                    '@type' => 'ImageObject',
                    'url' => $logoUrl,
                    'width' => 80,
                    'height' => 80
                ],
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $siteName,
                    'logo' => [
                        '@type' => 'ImageObject',
                        'url' => $logoUrl,
                        'width' => 80,
                        'height' => 80
                    ]
                ],
                'mainEntityOfPage' => [
                    '@type' => 'WebPage',
                    '@id' => $entityUrl
                ]
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        // Contact Page Schema
        if (is_object($entity) && property_exists($entity, 'type') && $entity->type === 'contact_page') {
            return json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'ContactPage',
                'headline' => $entity->name,
                'name' => $entity->name,
                'description' => $entity->description,
                'url' => $entity->url,
                'identifier' => $entity->url,
                'image' => [
                    '@type' => 'ImageObject',
                    'url' => $logoUrl,
                    'width' => 80,
                    'height' => 80
                ],
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $siteName,
                    'logo' => [
                        '@type' => 'ImageObject',
                        'url' => $logoUrl,
                        'width' => 80,
                        'height' => 80
                    ]
                ],
                'mainEntityOfPage' => [
                    '@type' => 'WebPage',
                    '@id' => $entity->url
                ]
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        return '';
    }

    public function getOrganizationJson()
    {
        $siteName = $this->scopeConfig->getValue('general/store_information/name', \Magento\Store\Model\ScopeInterface::SCOPE_STORE) ?: 'Andy Woolford';
        $logoUrl = $this->getViewFileUrl('images/logo.svg');
        $siteUrl = $this->getUrl('');

        return json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $siteName,
            'url' => $siteUrl,
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $logoUrl,
                'width' => 80,
                'height' => 80
            ],
            'sameAs' => [
                'https://www.linkedin.com/in/andywoolford',
                'https://www.youtube.com/@andywoolford6087',
                'https://www.instagram.com/andrewjwoolford/',
                'https://github.com/andrew-woolford'
                // Add other profiles if needed (Twitter, GitHub, etc.)
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function getMediaUrl(): string
    {
        return $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
    }
}
