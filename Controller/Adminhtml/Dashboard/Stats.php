<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Controller\Adminhtml\Dashboard;

use Furan\InfraHealth\Model\ProbeRunner;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * JSON for one probe. The only input is a probe code checked against the registered pool,
 * so callers can't steer probes at other hosts.
 */
class Stats extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Furan_InfraHealth::dashboard';

    public function __construct(
        Action\Context $context,
        private JsonFactory $jsonFactory,
        private ProbeRunner $runner
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        // Release the session lock so parallel probe requests don't queue.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $result = $this->jsonFactory->create()->setHeader('Cache-Control', 'no-store, max-age=0', true);
        $code = (string)$this->getRequest()->getParam('probe');
        if (!$this->runner->has($code)) {
            return $result->setHttpResponseCode(400)->setData(['error' => 'Unknown probe']);
        }
        return $result->setData($this->runner->runOne($code));
    }
}
