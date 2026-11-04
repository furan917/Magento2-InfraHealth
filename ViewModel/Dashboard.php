<?php
declare(strict_types=1);

namespace Furan\InfraHealth\ViewModel;

use Furan\InfraHealth\Model\ProbeRunner;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Page bootstrap: everything here is config-only, so the page renders without waiting on any backend.
 */
class Dashboard implements ArgumentInterface
{
    public function __construct(
        private UrlInterface $url,
        private ProbeRunner $runner
    ) {
    }

    public function getJsConfig(): array
    {
        return [
            'statsUrl' => $this->url->getUrl('infrahealth/dashboard/stats'),
            'probes' => $this->runner->probes(),
            'wiring' => $this->runner->wiring(),
        ];
    }
}
