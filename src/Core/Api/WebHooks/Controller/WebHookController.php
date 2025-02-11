<?php declare(strict_types=1);

namespace WalleePayment\Core\Api\WebHooks\Controller;

use Doctrine\DBAL\{
	Connection,
	TransactionIsolationLevel};
use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Checkout\Cart\Exception\OrderNotFoundException,
	Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity,
	Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity,
	Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler,
	Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates,
	Checkout\Order\OrderEntity,
	Checkout\Order\SalesChannel\OrderService,
	Framework\Context,
	Framework\DataAbstractionLayer\Search\Criteria,
	Framework\DataAbstractionLayer\Search\Sorting\FieldSorting,
	Framework\Routing\Annotation\RouteScope,
	System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions,
	System\StateMachine\Exception\IllegalTransitionException};
use Shopware\Core\Checkout\Order\OrderStates;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\{HttpFoundation\JsonResponse,
	HttpFoundation\ParameterBag,
	HttpFoundation\Request,
	HttpFoundation\Response,
	Routing\Annotation\Route};
use Wallee\Sdk\{
	Model\RefundState,
	Model\Transaction,
	Model\TransactionInvoiceState,
	Model\TransactionState,};
use WalleePayment\Core\{
	Api\OrderDeliveryState\Handler\OrderDeliveryStateHandler,
	Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService,
	Api\Refund\Service\RefundService,
	Api\Transaction\Service\OrderMailService,
	Api\Transaction\Service\TransactionService,
	Api\WebHooks\Struct\WebHookRequest,
	Settings\Service\SettingsService,
	Util\Payload\TransactionPayload};

/**
 * Class WebHookController
 *
 * @package WalleePayment\Core\Api\WebHooks\Controller
 *
 * @RouteScope(scopes={"api"})
 */
class WebHookController extends AbstractController {

	/**
	 * @var \Doctrine\DBAL\Connection
	 */
	protected $connection;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	protected $logger;

	/**
	 * @var \WalleePayment\Core\Api\Transaction\Service\OrderMailService
	 */
	protected $orderMailService;

	/**
	 * @var \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler
	 */
	protected $orderTransactionStateHandler;

	/**
	 * @var \WalleePayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService
	 */
	protected $paymentMethodConfigurationService;

	/**
	 * @var \WalleePayment\Core\Settings\Struct\Settings
	 */
	protected $settings;

	/**
	 * @var \WalleePayment\Core\Settings\Service\SettingsService
	 */
	protected $settingsService;

	/**
	 * @var \WalleePayment\Core\Api\Refund\Service\RefundService
	 */
	protected $refundService;

	/**
	 * @var \WalleePayment\Core\Api\Transaction\Service\TransactionService
	 */
	protected $transactionService;

	/**
	 * Transaction Final States
	 *
	 * @var array
	 */
	protected $transactionFinalStates = [
		OrderTransactionStates::STATE_CANCELLED,
		OrderTransactionStates::STATE_PAID,
		OrderTransactionStates::STATE_REFUNDED,
	];
	/**
	 * Transaction Failed States
	 *
	 * @var array
	 */
	protected $transactionFailedStates = [
		TransactionState::DECLINE,
		TransactionState::FAILED,
		TransactionState::VOIDED,
	];

	protected $walleeTransactionSuccessStates = [
		TransactionState::AUTHORIZED,
		TransactionState::COMPLETED,
		TransactionState::FULFILL,
	];

	/**
	 * @var \Shopware\Core\Checkout\Order\OrderEntity
	 */
	private $orderEntity;

	/**
	 * @var \Shopware\Core\Checkout\Order\SalesChannel\OrderService
	 */
	private $orderService;

	/**
	 * WebHookController constructor.
	 *
	 * @param \Doctrine\DBAL\Connection                                                                                   $connection
	 * @param \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler                       $orderTransactionStateHandler
	 * @param \Shopware\Core\Checkout\Order\SalesChannel\OrderService                                                     $orderService
	 * @param \WalleePayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService $paymentMethodConfigurationService
	 * @param \WalleePayment\Core\Api\Refund\Service\RefundService                                         $refundService
	 * @param \WalleePayment\Core\Api\Transaction\Service\OrderMailService                                 $orderMailService
	 * @param \WalleePayment\Core\Api\Transaction\Service\TransactionService                               $transactionService
	 * @param \WalleePayment\Core\Settings\Service\SettingsService                                         $settingsService
	 */
	public function __construct(
		Connection $connection,
		OrderTransactionStateHandler $orderTransactionStateHandler,
		OrderService $orderService,
		PaymentMethodConfigurationService $paymentMethodConfigurationService,
		RefundService $refundService,
		OrderMailService $orderMailService,
		TransactionService $transactionService,
		SettingsService $settingsService
	)
	{
		$this->connection                        = $connection;
		$this->orderTransactionStateHandler      = $orderTransactionStateHandler;
		$this->paymentMethodConfigurationService = $paymentMethodConfigurationService;
		$this->refundService                     = $refundService;
		$this->orderMailService                  = $orderMailService;
		$this->transactionService                = $transactionService;
		$this->settingsService                   = $settingsService;
		$this->orderService                      = $orderService;
	}

	/**
	 * @param \Psr\Log\LoggerInterface $logger
	 *
	 * @internal
	 * @required
	 *
	 */
	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * This is the method Wallee calls
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Shopware\Core\Framework\Context          $context
	 * @param string                                    $salesChannelId
	 *
	 * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\Response
	 * @Route(
	 *     "/api/_action/wallee/webHook/callback/{salesChannelId}",
	 *     name="api.action.wallee.webhook.update",
	 *     options={"seo": "false"},
	 *     defaults={"csrf_protected"=false, "XmlHttpRequest"=true, "auth_required"=false},
	 *     methods={"POST"}
	 * )
	 */
	public function callback(Request $request, Context $context, string $salesChannelId): Response
	{
		$status       = Response::HTTP_UNPROCESSABLE_ENTITY;
		$callBackData = new WebHookRequest();
		try {
			// Configuration
			$salesChannelId = $salesChannelId == 'null' ? null : $salesChannelId;
			$this->settings = $this->settingsService->getSettings($salesChannelId);

			$callBackData->assign(json_decode($request->getContent(), true));

			switch ($callBackData->getListenerEntityTechnicalName()) {
				case WebHookRequest::PAYMENT_METHOD_CONFIGURATION:
					return $this->updatePaymentMethodConfiguration($context, $salesChannelId);
				case WebHookRequest::REFUND:
					return $this->updateRefund($callBackData, $context);
				case WebHookRequest::TRANSACTION:
					return $this->updateTransaction($callBackData, $context);
				case WebHookRequest::TRANSACTION_INVOICE:
					return $this->updateTransactionInvoice($callBackData, $context);
				default:
					$this->logger->critical(__CLASS__ . ' : ' . __FUNCTION__ . ' : Listener not implemented : ', $callBackData->jsonSerialize());
			}
			$status = Response::HTTP_OK;
		} catch (\Exception $exception) {
			$this->logger->critical(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage(), $callBackData->jsonSerialize());
		}
		return new JsonResponse(['data' => $callBackData], $status);
	}

	/**
	 * Handle Wallee Payment Method Configuration callback
	 *
	 * @param \Shopware\Core\Framework\Context $context
	 * @param string                           $salesChannelId
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 * @throws \Wallee\Sdk\ApiException
	 * @throws \Wallee\Sdk\Http\ConnectionException
	 * @throws \Wallee\Sdk\VersioningException
	 */
	private function updatePaymentMethodConfiguration(Context $context, string $salesChannelId = null): Response
	{
		$result = $this->paymentMethodConfigurationService->setSalesChannelId($salesChannelId)->synchronize($context);

		return new JsonResponse(['result' => $result]);
	}

	/**
	 * Handle Wallee Refund callback
	 *
	 * @param \WalleePayment\Core\Api\WebHooks\Struct\WebHookRequest $callBackData
	 * @param \Shopware\Core\Framework\Context                                      $context
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function updateRefund(WebHookRequest $callBackData, Context $context): Response
	{
		$status = Response::HTTP_UNPROCESSABLE_ENTITY;

		try {
			/**
			 * @var \Wallee\Sdk\Model\Transaction $transaction
			 */
			$refund  = $this->settings->getApiClient()->getRefundService()
									  ->read($callBackData->getSpaceId(), $callBackData->getEntityId());
			$orderId = $refund->getTransaction()->getMetaData()[TransactionPayload::WALLEE_METADATA_ORDER_ID];

			if(!empty($orderId)) {

				$this->executeLocked($orderId, $context, function () use ($orderId, $refund, $context) {

					$this->refundService->upsert($refund, $context);

					$orderTransactionId = $refund->getTransaction()->getMetaData()[TransactionPayload::WALLEE_METADATA_ORDER_TRANSACTION_ID];
					$orderTransaction   = $this->getOrderTransaction($orderId, $context);
					if (
						in_array(
							$orderTransaction->getStateMachineState()->getTechnicalName(),
							[
								OrderTransactionStates::STATE_PAID,
								OrderTransactionStates::STATE_PARTIALLY_PAID,
							]
						) &&
						($refund->getState() == RefundState::SUCCESSFUL)
					) {
						if ($refund->getAmount() == $orderTransaction->getAmount()->getTotalPrice()) {
							$this->orderTransactionStateHandler->refund($orderTransactionId, $context);
						} else {
							if ($refund->getAmount() < $orderTransaction->getAmount()->getTotalPrice()) {
								$this->orderTransactionStateHandler->refundPartially($orderTransactionId, $context);
							}
						}
					} elseif ($orderTransaction->getStateMachineState()->getTechnicalName()
						=== OrderTransactionStates::STATE_PARTIALLY_REFUNDED &&
						($refund->getState() == RefundState::SUCCESSFUL)
					) {
						$transactionByOrderTransactionId = $this->transactionService->getByOrderTransactionId($orderTransactionId, $context);
						$totalRefundedAmount  = $this->getTotalRefundedAmount($transactionByOrderTransactionId->getTransactionId(), $context);
						if (floatval($orderTransaction->getAmount()->getTotalPrice()) - $totalRefundedAmount <= 0) {
							$this->orderTransactionStateHandler->refund($orderTransactionId, $context);
						}
					}

				});
			}

			$status = Response::HTTP_OK;
		} catch (OrderNotFoundException $exception) {
			$status = Response::HTTP_OK;
			$this->logger->info(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage(), $callBackData->jsonSerialize());
		} catch (IllegalTransitionException $exception) {
			$status = Response::HTTP_OK;
			$this->logger->info(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage(), $callBackData->jsonSerialize());
		} catch (\Exception $exception) {
			$this->logger->critical(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage(), $callBackData->jsonSerialize());
		}

		return new JsonResponse(['data' => $callBackData->jsonSerialize()], $status);
	}

	/**
	 * @param int $transactionId
	 * @param Context $context
	 * @return float
	 */
	private function getTotalRefundedAmount(int $transactionId, Context $context): float
	{
		$amount = 0;
		$refunds = $this->transactionService->getRefundEntityCollectionByTransactionId($transactionId, $context);
		foreach ($refunds as $refund) {
			$amount += floatval($refund->getData()['amount'] ?? 0);
		}

		return (float) (string) $amount;
	}

	/**
	 * @param string   $orderId
	 * @param Context  $context
	 * @param callable $operation
	 *
	 * @return mixed
	 * @throws \Exception
	 */
	private function executeLocked(string $orderId, Context $context, callable $operation)
	{
		//$this->connection->setTransactionIsolation(TransactionIsolationLevel::READ_COMMITTED);
		//$this->connection->beginTransaction();
		try {

			$data = [
				'id'                         => $orderId,
				'wallee_lock' => date('Y-m-d H:i:s'),
			];

			$order = $this->container->get('order.repository')->search(new Criteria([$orderId]), $context)->first();

			if(empty($order)){
				throw new OrderNotFoundException($orderId);
			}

			$this->container->get('order.repository')->upsert([$data], $context);

			$result = $operation();

			//$this->connection->commit();
			return $result;
		} catch (\Exception $exception) {
			//$this->connection->rollBack();
			throw $exception;
		}
	}

	/**
	 * @param String                           $orderId
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity
	 */
	private function getOrderTransaction(String $orderId, Context $context): OrderTransactionEntity
	{
		return $this->getOrderEntity($orderId, $context)->getTransactions()->last();
	}

	/**
	 * Get order
	 *
	 * @param String                           $orderId
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return \Shopware\Core\Checkout\Order\OrderEntity
	 */
	private function getOrderEntity(string $orderId, Context $context): OrderEntity
	{
		if (is_null($this->orderEntity)) {
			$criteria = (new Criteria([$orderId]))
				->addAssociations(['deliveries', 'transactions',])
				->addSorting(new FieldSorting('createdAt'));

			try {
				$this->orderEntity = $this->container->get('order.repository')->search(
					$criteria,
					$context
				)->first();
				if (is_null($this->orderEntity)) {
					throw new OrderNotFoundException($orderId);
				}
			} catch (\Exception $e) {
				throw new OrderNotFoundException($orderId);
			}
		}

		return $this->orderEntity;
	}

	/**
	 * Handle Wallee Transaction callback
	 *
	 * @param \WalleePayment\Core\Api\WebHooks\Struct\WebHookRequest $callBackData
	 * @param \Shopware\Core\Framework\Context                                      $context
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	private function updateTransaction(WebHookRequest $callBackData, Context $context): Response
	{
		$status = Response::HTTP_UNPROCESSABLE_ENTITY;

		try {
			/**
			 * @var \Wallee\Sdk\Model\Transaction $transaction
			 * @var \Shopware\Core\Checkout\Order\OrderEntity    $order
			 */
			$transaction = $this->settings->getApiClient()
										  ->getTransactionService()
										  ->read($callBackData->getSpaceId(), $callBackData->getEntityId());
			$orderId     = $transaction->getMetaData()[TransactionPayload::WALLEE_METADATA_ORDER_ID];
			if(!empty($orderId) && !$transaction->getParent()) {
				$this->executeLocked($orderId, $context, function () use ($orderId, $transaction, $context, $callBackData) {
					$this->transactionService->upsert($transaction, $context);
					$orderTransactionId = $transaction->getMetaData()[TransactionPayload::WALLEE_METADATA_ORDER_TRANSACTION_ID];
					$orderTransaction   = $this->getOrderTransaction($orderId, $context);
					$this->logger->info("OrderId: {$orderId} Current state: {$orderTransaction->getStateMachineState()->getTechnicalName()}");

					if (!in_array(
						$orderTransaction->getStateMachineState()->getTechnicalName(),
						$this->transactionFinalStates
					)) {
						switch ($transaction->getState()) {
							case TransactionState::FAILED:
								$this->orderTransactionStateHandler->fail($orderTransactionId, $context);
								$this->unholdAndCancelDelivery($orderId, $context);
								break;
							case TransactionState::DECLINE:
							case TransactionState::VOIDED:
								$this->orderTransactionStateHandler->cancel($orderTransactionId, $context);
								$this->unholdAndCancelDelivery($orderId, $context);
								break;
							case TransactionState::FULFILL:
								$this->unholdDelivery($orderId, $context);
								break;
							case TransactionState::AUTHORIZED:
								$this->orderTransactionStateHandler->process($orderTransactionId, $context);
								$this->sendEmail($transaction, $context);
								break;
							default:
								break;
						}
					}

				});
			}
			$status = Response::HTTP_OK;
		} catch (OrderNotFoundException $exception) {
			$status = Response::HTTP_OK;
			$this->logger->info(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage(), $callBackData->jsonSerialize());
		} catch (IllegalTransitionException $exception) {
			$status = Response::HTTP_OK;
			$this->logger->info(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage(), $callBackData->jsonSerialize());
		} catch (\Exception $exception) {
			$this->logger->critical(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage(), $callBackData->jsonSerialize());
		}

		return new JsonResponse(['data' => $callBackData->jsonSerialize()], $status);
	}

	/**
	 * @param \Wallee\Sdk\Model\Transaction $transaction
	 * @param \Shopware\Core\Framework\Context             $context
	 */
	protected function sendEmail(Transaction $transaction, Context $context): void
	{
		$orderId = $transaction->getMetaData()[TransactionPayload::WALLEE_METADATA_ORDER_ID];
		if ($this->settings->isEmailEnabled() && in_array($transaction->getState(), $this->walleeTransactionSuccessStates)) {
			$this->orderMailService->send($orderId, $context);
		}
	}

	/**
	 * Handle Wallee TransactionInvoice callback
	 *
	 * @param \WalleePayment\Core\Api\WebHooks\Struct\WebHookRequest $callBackData
	 * @param \Shopware\Core\Framework\Context                                      $context
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function updateTransactionInvoice(WebHookRequest $callBackData, Context $context): Response
	{
		$status = Response::HTTP_UNPROCESSABLE_ENTITY;

		try {
			/**
			 * @var \Wallee\Sdk\Model\Transaction        $transaction
			 * @var \Wallee\Sdk\Model\TransactionInvoice $transactionInvoice
			 */
			$transactionInvoice = $this->settings->getApiClient()->getTransactionInvoiceService()
												 ->read($callBackData->getSpaceId(), $callBackData->getEntityId());
			$orderId            = $transactionInvoice->getCompletion()
													 ->getLineItemVersion()
													 ->getTransaction()
													 ->getMetaData()[TransactionPayload::WALLEE_METADATA_ORDER_ID];
			if(!empty($orderId)) {
				$this->executeLocked($orderId, $context, function () use ($orderId, $transactionInvoice, $context) {

					$orderTransactionId = $transactionInvoice->getCompletion()
															 ->getLineItemVersion()
															 ->getTransaction()
															 ->getMetaData()[TransactionPayload::WALLEE_METADATA_ORDER_TRANSACTION_ID];
					$orderTransaction   = $this->getOrderTransaction($orderId, $context);
					if (!in_array(
						$orderTransaction->getStateMachineState()->getTechnicalName(),
						$this->transactionFinalStates
					)) {
						switch ($transactionInvoice->getState()) {
							case TransactionInvoiceState::DERECOGNIZED:
								$this->orderTransactionStateHandler->cancel($orderTransactionId, $context);
								break;
							case TransactionInvoiceState::NOT_APPLICABLE:
							case TransactionInvoiceState::PAID:
								$this->orderTransactionStateHandler->paid($orderTransactionId, $context);
								$this->unholdDelivery($orderTransactionId, $context);
								break;
							default:
								break;
						}
					}
				});
			}
			$status = Response::HTTP_OK;
		} catch (OrderNotFoundException $exception) {
			$status = Response::HTTP_OK;
			$this->logger->info(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage(), $callBackData->jsonSerialize());
		} catch (IllegalTransitionException $exception) {
			$status = Response::HTTP_OK;
			$this->logger->info(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage(), $callBackData->jsonSerialize());
		} catch (\Exception $exception) {
			$this->logger->critical(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage(), $callBackData->jsonSerialize());
		}

		return new JsonResponse(['data' => $callBackData->jsonSerialize()], $status);
	}

	/**
	 * Hold delivery
	 *
	 * @param string                           $orderId
	 * @param \Shopware\Core\Framework\Context $context
	 */
	private function unholdDelivery(string $orderId, Context $context): void
	{
		try {
			/**
			 * @var OrderDeliveryStateHandler $orderDeliveryStateHandler
			 */
			$order = $this->getOrderEntity($orderId, $context);
			/**
			 * @var OrderDeliveryEntity $orderDelivery
			 */
			$orderDelivery = $order->getDeliveries()->last();
			if ($orderDelivery->getStateMachineState()->getTechnicalName() !== OrderDeliveryStateHandler::STATE_HOLD){
				return;
			}
			$orderDeliveryStateHandler = $this->container->get(OrderDeliveryStateHandler::class);
			$orderDeliveryStateHandler->unhold($orderDelivery->getId(), $context);
		} catch (\Exception $exception) {
			$this->logger->info($exception->getMessage(), $exception->getTrace());
		}
	}

	/**
	 * Unhold and cancel delivery
	 *
	 * @param string                           $orderId
	 * @param \Shopware\Core\Framework\Context $context
	 */
	private function unholdAndCancelDelivery(string $orderId, Context $context): void
	{
		$order = $this->getOrderEntity($orderId, $context);
		try {
			$this->orderService->orderStateTransition(
				$order->getId(),
				StateMachineTransitionActions::ACTION_CANCEL,
				new ParameterBag(),
				$context
			);
		} catch (\Exception $exception) {
			$this->logger->info($exception->getMessage(), $exception->getTrace());
		}

		try {
			/**
			 * @var OrderDeliveryStateHandler $orderDeliveryStateHandler
			 */
			$orderDeliveryStateHandler = $this->container->get(OrderDeliveryStateHandler::class);
			/**
			 * @var OrderDeliveryEntity $orderDelivery
			 */
			$orderDelivery = $order->getDeliveries()->last();
			if ($orderDelivery->getStateMachineState()->getTechnicalName() !== OrderDeliveryStateHandler::STATE_HOLD){
				return;
			}
			$orderDeliveryId = $orderDelivery->getId();
			$orderDeliveryStateHandler->unhold($orderDeliveryId, $context);
			$orderDeliveryStateHandler->cancel($orderDeliveryId, $context);
		} catch (\Exception $exception) {
			$this->logger->info($exception->getMessage(), $exception->getTrace());
		}
	}
}
