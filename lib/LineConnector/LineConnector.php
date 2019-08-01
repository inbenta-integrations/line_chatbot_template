<?php
namespace Inbenta\LineConnector;

use Exception;
use Inbenta\ChatbotConnector\ChatbotConnector;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;
use Inbenta\LineConnector\ExternalAPI\LineAPIClient;
use Inbenta\LineConnector\ExternalDigester\LineDigester;

class LineConnector extends ChatbotConnector
{

	public function __construct($appPath)
	{
		// Initialize and configure specific components for Line
		try {
			parent::__construct($appPath);

			// Obtain LINE signature
			$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'];

			// Initialize base components
			$request = file_get_contents('php://input');
        	if (is_string($request)){
            	$request = json_decode($request);
        	}

        	// If it's a verify webhook event
			if(isset($request->events) && isset($request->events[0]) && $request->events[0]->replyToken && is_numeric($request->events[0]->replyToken) && (int)$request->events[0]->replyToken === 0) {
				echo 'Webhook active';
				die();
			}

			$conversationConf = array('configuration' => $this->conf->get('conversation.default'), 'userType' => $this->conf->get('conversation.user_type'), 'environment' => $this->environment);
			$this->session 		= new SessionManager($this->getExternalIdFromRequest());
			$this->botClient 	= new ChatbotAPIClient($this->conf->get('api.key'), $this->conf->get('api.secret'), $this->session, $conversationConf);

			// Retrieve Line tokens from ExtraInfo and update configuration
			$this->getTokensFromExtraInfo();

			// Try to get the translations from ExtraInfo and update the language manager
			$this->getTranslationsFromExtraInfo('line','translations');
			// Instance application components
			$externalClient 		= new LineAPIClient(
				$this->conf->get('line.channel_id'),
				$this->conf->get('line.channel_secret'),
				$this->conf->get('line.switcher_destination'),
				$this->conf->get('line.switcher_secret'),
				$this->conf->get('line.service_code'),
				$request,
				$signature
			); // Instance Line client
			$externalDigester 		= new LineDigester($this->lang, $this->conf->get('conversation.digester'), $this->session); // Instance Line digester
			$this->initComponents($externalClient, null, $externalDigester); // 2nd parameter is null because Inbenta's Hyperchat client is never initiated
		}
		catch (Exception $e) {
			echo json_encode(["error" => $e->getMessage()]);
			die();
		}
	}

	/**
	 *	Retrieve Line tokens from ExtraInfo
	 */
	protected function getTokensFromExtraInfo()
	{
		$line_conf = [];
		$extraInfoDataLine = $this->botClient->getExtraInfo('line');
		foreach ($extraInfoDataLine->results as $element) {
			if($element->name == "line_conf"){
				$environment = $this->environment;
				$line_conf = $element->value->$environment[0];
			}
		}
		if (!isset($line_conf->channel_id) || !isset($line_conf->channel_secret)) {
			throw new Exception("Invalid Channel ID/Secret retreived from extraInfo");
			die();
		}
		// Set to null the optional configuration if is not set
		if (!isset($line_conf->switcher_destination)) $line_conf->switcher_destination = null;
		if (!isset($line_conf->switcher_secret)) $line_conf->switcher_secret = null;
		if (!isset($line_conf->service_code)) $line_conf->service_code = null;

		// Store tokens in conf
		$this->conf->set('line.channel_id', $line_conf->channel_id);
		$this->conf->set('line.channel_secret', $line_conf->channel_secret);
		$this->conf->set('line.switcher_destination', $line_conf->switcher_destination);
		$this->conf->set('line.switcher_secret', $line_conf->switcher_secret);
		$this->conf->set('line.service_code', $line_conf->service_code);
	}

	/**
	 *	Return external id from request (Hyperchat of Line)
	 */
	protected function getExternalIdFromRequest()
	{
		// Try to get user_id from a Line message request
		$externalId = LineAPIClient::buildExternalIdFromRequest();
		if (empty($externalId)) {
			$api_key = $this->conf->get('api.key');
			if (isset($_SERVER['HTTP_X_HOOK_SECRET'])) {
				// Create a temporary session_id from a HyperChat webhook linking request
				$externalId = "hc-challenge-" . preg_replace("/[^A-Za-z0-9 ]/", '', $api_key);
			} else {
				throw new Exception("Invalid request");
				die();
			}
		}
		return $externalId;
	}

	/**
     * 	Send messages to the external service. Messages should be formatted as a ChatbotAPI response
     */
	protected function sendMessagesToExternal( $messages, $digestedMessages = array() )
	{
		// Digest the bot response into the external service format
		$digestedBotResponse = array();
		if (!empty((array) $messages)) {
			$digestedBotResponse = $this->digester->digestFromApi($messages,  $this->session->get('lastUserQuestion'));
		}
		if ($digestedMessages) {
			foreach ($digestedMessages as $message) {
				$digestedBotResponse[] = $message;
			}
		}
		$this->externalClient->sendMessage($digestedBotResponse);
	}


	/**
	 *	Check if it's needed to perform any action other than a standard user-bot interaction
	 */
	protected function handleNonBotActions($digestedRequest)
	{
		// If user answered to an ask-to-escalate question, handle it
		if ($this->session->get('askingForEscalation', false)) {
			$this->handleEscalation($digestedRequest);
		}
		// If the user clicked in a Federated Bot option, handle its request
		if (count($digestedRequest) && isset($digestedRequest[0]['extendedContentAnswer'])) {
			$answer = json_decode(json_encode($digestedRequest[0]['extendedContentAnswer']));
			$this->displayFederatedBotAnswer($answer);
			die();
		}
	}

	/**
	 *	Handle an incoming request for the ChatBot
	 */
	public function handleBotActions($externalRequest)
	{
		$needEscalation = false;
		$needContentRating = false;
		foreach ($externalRequest as $message) {
			$digestedMessages = array();
			// Check if is needed to execute any preset 'command'
			$this->handleCommands($message);
			// Store the last user text message to session
			$this->saveLastTextMessage($message);
			// Check if is needed to ask for a rating comment
			$message = $this->checkContentRatingsComment($message);
			// Send the messages received from the external service to the ChatbotAPI
			if (isset($message['skipBot'])) {
				unset($message['skipBot']);
				$botResponse = [];
				$digestedMessages = $message;
			} else {
				$botResponse = $this->sendMessageToBot($message);
			}
			// Transform the "Thanks!" message after rating to a sticker if enabled
			list($botResponse, $digestedMessages) = $this->thanksRatingToSticker($botResponse, $digestedMessages);

			// Check if escalation is needed
			$needEscalation = $this->checkEscalation($botResponse) ? true : $needEscalation;
			if ($needEscalation) {
				$digestedMessages[] = $this->handleEscalation();
			}
			// Check if is needed to display content ratings
			$hasRating = $this->checkContentRatings($botResponse);
			// $needContentRating is the actual rating code
			$needContentRating = $hasRating ? $hasRating : $needContentRating;
			// Add content rating messages if needed and not in chat nor asking to escalate
			if ($needContentRating && !$this->session->get('askingForEscalation', false)) {
				$ratingOptions = $this->conf->get('conversation.content_ratings.ratings');
				$digestedMessages[] = $this->digester->buildContentRatingsMessage($ratingOptions, $needContentRating);
			}
			// Send the messages received from ChatbotApi back to the external service
			$this->sendMessagesToExternal($botResponse, $digestedMessages);
		}
	}

    /**
     * 	Ask the user if wants to talk with a human and handle the answer
     */
	protected function handleEscalation($userAnswer = null)
	{
		// Ask the user if wants to escalate
		if (!$this->session->get('askingForEscalation', false)) {
			// Ask the user if wants to escalate
			$this->session->set('askingForEscalation', true);
			return $this->digester->buildEscalationMessage();
		} else {
			// Handle user response to an escalation question
			$this->session->set('askingForEscalation', false);
			// Reset escalation counters
			$this->session->set('noResultsCount', 0);
			$this->session->set('negativeRatingCount', 0);
			if (count($userAnswer) && isset($userAnswer[0]['escalateOption'])) {
			    if ($userAnswer[0]['escalateOption']) {
					$this->escalateToAgent();
			    } else {
			        $this->trackContactEvent("CONTACT_REJECTED");
			        $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('escalation_rejected')));
			    }
			    die();
			}
		}
	}

    /**
     * 	Log an event to the bot
     */
	protected function sendEventToBot($event)
	{
		$bot_tracking_events = ['rate', 'click'];
		if (!in_array($event['type'], $bot_tracking_events)) {
			die();
		}

		$response = $this->botClient->trackEvent($event);
		switch ($event['type']) {
			case 'rate':
				$askingRatingComment    = $this->session->has('askingRatingComment') && $this->session->get('askingRatingComment') != false;
                $willEscalate           = $this->shouldEscalateFromNegativeRating();
                if ($askingRatingComment && !$willEscalate) {
					// Ask for a comment on a content-rating
					return $this->buildTextMessage($this->lang->translate('ask_rating_comment'));
				} else {
					// Forget we were asking for a rating comment
					$this->session->set('askingRatingComment', false);
					// Send 'Thanks' message after rating
					return $this->buildTextMessage($this->lang->translate('thanks'));
				}

			break;
		}
	}

    /**
     * 	Ask the user if wants to talk with a human and handle the answer
     */

	//Tries to start a chat with an agent with an external service
	protected function escalateToAgent()
	{
		$useExternalService = $this->conf->get('chat.chat.useExternal');
		if ($useExternalService) {
			if ($this->conf->get('line.switcher_destination')) {
			    // Inform the user that the chat is being created
				$this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('creating_chat')));
			    // Connect with external client
				$this->externalClient->sendSwitcherEvent();
				$this->externalClient->sendSwitcherNotice();
				$this->trackContactEvent("CONTACT_ATTENDED");
			} else {
				$this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('error_creating_chat')));
			}
		} else {
			// Use the parent method to escalate to HyperChat
			parent::escalateToAgent();
		}
	}

    /**
     * 	Transform the "Thanks!" message after rating to a sticker if enabled
     */
	protected function thanksRatingToSticker($botResponse, $digestedMessages)
	{
		if ($this->conf->get('conversation.content_ratings.sticker') && is_object($botResponse) && isset($botResponse->message) && $botResponse->message === $this->lang->translate('thanks')) {
			$thanksRatingStickers = $this->conf->get('conversation.digester.stickerReplies.thanksRatingStickers');
			if (is_array($thanksRatingStickers) && count($thanksRatingStickers) >= 1) {
				$thanksSticker = $thanksRatingStickers[array_rand($thanksRatingStickers)];
				$thanksSticker['type'] = 'sticker';
				$botResponse = [];
				$digestedMessages = array(
					'message' => $thanksSticker
				);
			}
		}
		return array($botResponse, $digestedMessages);
	}


}
