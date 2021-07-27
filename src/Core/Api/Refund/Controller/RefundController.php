<?php declare(strict_types=1);

namespace WalleePayment\Core\Api\Refund\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\{
	HttpFoundation\Request,
	HttpFoundation\Response,
	Routing\Annotation\Route,};
use WalleePayment\Core\{
	Api\Refund\Service\RefundService,
	Settings\Service\SettingsService};


/**
 * Class RefundController
 *
 * @package WalleePayment\Core\Api\Refund\Controller
 *
 * @RouteScope(scopes={"api"})
 */
class RefundController extends AbstractController {

	/**
	 * @var \WalleePayment\Core\Api\Refund\Service\RefundService
	 */
	protected $refundService;

	/**
	 * @var \WalleePayment\Core\Settings\Service\SettingsService
	 */
	protected $settingsService;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	protected $logger;

	/**
	 * RefundController constructor.
	 *
	 * @param \WalleePayment\Core\Api\Refund\Service\RefundService $refundService
	 * @param \WalleePayment\Core\Settings\Service\SettingsService $settingsService
	 */
	public function __construct(RefundService $refundService, SettingsService $settingsService)
	{
		$this->settingsService = $settingsService;
		$this->refundService   = $refundService;
	}

	/**
	 * @param \Psr\Log\LoggerInterface $logger
	 * @internal
	 * @required
	 *
	 */
	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Shopware\Core\Framework\Context          $context
	 * @return \Symfony\Component\HttpFoundation\Response
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @throws \Wallee\Sdk\VersioningException
	 * @Route(
	 *     "/api/_action/wallee/refund/create-refund/",
	 *     name="api.action.wallee.refund.create-refund",
	 *     methods={"POST"}
	 *     )
	 */
	public function createRefund(Request $request, Context $context): Response
	{
		$salesChannelId   = $request->request->get('salesChannelId');
		$transactionId    = $request->request->get('transactionId');
		$quantity         = (int) $request->request->get('quantity');
		$lineItemId       = $request->request->get('lineItemId');

		$settings  = $this->settingsService->getSettings($salesChannelId);
		$apiClient = $settings->getApiClient();

		$transaction = $apiClient->getTransactionService()->read($settings->getSpaceId(), $transactionId);
		$this->refundService->create($transaction, $context, $lineItemId, $quantity);

		return new Response(null, Response::HTTP_NO_CONTENT);
	}
}