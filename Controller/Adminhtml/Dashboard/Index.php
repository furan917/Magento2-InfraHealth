<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Furan_InfraHealth::dashboard';

    public function __construct(Action\Context $context, private PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Furan_InfraHealth::infra_health');
        $page->getConfig()->getTitle()->prepend(__('Infrastructure Health'));
        return $page;
    }
}
