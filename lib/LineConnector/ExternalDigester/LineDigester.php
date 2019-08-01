<?php
namespace Inbenta\LineConnector\ExternalDigester;

use \Exception;
use Inbenta\ChatbotConnector\ExternalDigester\Channels\DigesterInterface;

class LineDigester extends DigesterInterface
{

	protected $conf;
	protected $channel;
	protected $langManager;
	protected $session;
	protected $externalMessageTypes = array(
		'text',
		'postback',
		'sticker',
		'image',
		'audio',
		'file',
		'location',
		'video',
		'unknown'
	);

	public function __construct($langManager, $conf, $session)
	{
		$this->langManager = $langManager;
		$this->channel = 'Line';
		$this->conf = $conf;
		$this->session = $session;
	}

	/**
	*	Returns the name of the channel
	*/
	public function getChannel()
	{
		return $this->channel;
	}

	/**
	**	Checks if a request belongs to the digester channel
	**/
	public static function checkRequest($request)
	{
		$request = json_decode($request);

		$isPage 	 = isset($request->object) && $request->object == "page";
		$isMessaging = isset($request->entry) && isset($request->entry[0]) && isset($request->entry[0]->messaging);
		if ($isPage && $isMessaging && count((array)$request->entry[0]->messaging)) {
			return true;
		}
		return false;
	}

	/**
	**	Formats a channel request into an Inbenta Chatbot API request
	**/
	public function digestToApi($request)
	{
		$request = json_decode($request);
		if(isset($request->events)){
			$events = $request->events;
		} else {
			return [];
		}
		$output = [];

		foreach ($events as $event) {
			$eventType = $this->checkExternalMessageType($event);
			$digester = 'digestFromLine' . ucfirst($eventType);

			//Check if there are more than one responses from one incoming message
			$digestedMessage = $this->$digester($event);
			if (isset($digestedMessage['multiple_output'])) {
				foreach ($digestedMessage['multiple_output'] as $event) {
					$output[] = $event;
				}
			} else {
				$output[] = $digestedMessage;
			}
		}
		return $output;
	}

	/**
	**	Formats an Inbenta Chatbot API response into a channel request
	**/
	public function digestFromApi($request, $lastUserQuestion='')
	{
		//Parse request messages
		if (isset($request->answers) && is_array($request->answers)) {
			$messages = $request->answers;
		} elseif ($this->checkApiMessageType($request) !== null) {
			$messages = array('answers' => $request);
		} else {
			throw new Exception("Unknown ChatbotAPI response: " . json_encode($request, true));
		}

		$output = [];
		foreach ($messages as $msg) {
			if (!$msg->message || $msg->message === '') continue; // Do not digest empty responses
			$msgType = $this->checkApiMessageType($msg);
			$digester = 'digestFromApi' . ucfirst($msgType);
			$digestedMessage = $this->$digester($msg, $lastUserQuestion);

			//Check if there are more than one responses from one incoming message
			if (isset($digestedMessage['multiple_output'])) {
				// foreach ($digestedMessage['multiple_output'] as $message) {
					$output = $digestedMessage['multiple_output'];
				// }
			} else {
				$output[] = $digestedMessage;
			}
		}
		return $output;
	}

	/**
	**	Classifies the external message into one of the defined $externalMessageTypes
	**/
	protected function checkExternalMessageType($event)
	{
		foreach ($this->externalMessageTypes as $type) {
			$checker = 'isLine' . ucfirst($type);

			if ($this->$checker($event)) {
				return $type;
			}
		}
	}

	/**
	**	Classifies the API message into one of the defined $apiMessageTypes
	**/
	protected function checkApiMessageType($message)
	{
		foreach ( $this->apiMessageTypes as $type ) {
			$checker = 'isApi' . ucfirst($type);

			if ($this->$checker($message)) {
				return $type;
			}
		}
		return null;
	}

	/********************** EXTERNAL MESSAGE TYPE CHECKERS **********************/

	protected function isLineText($event)
	{
		$isText = isset($event->message) && $event->message->type === "text";
		return $isText;
	}

	protected function isLinePostback($event)
	{
		$isPostback = isset($event->postback) && $event->type === "postback";
		return $isPostback;
	}

	protected function isLineImage($event)
	{
		return isset($event->message) && $event->message->type === "image";
	}

	protected function isLineSticker($event)
	{
		return isset($event->message) && $event->message->type === "sticker";
	}

	protected function isLineVideo($event)
	{
		return isset($event->message) && $event->message->type === "video";
	}

	protected function isLineFile($event)
	{
		return isset($event->message) && $event->message->type === "file";
	}

	protected function isLineAudio($event)
	{
		return isset($event->message) && $event->message->type === "audio";
	}

	protected function isLineLocation($event)
	{
		return isset($event->message) && $event->message->type === "location";
	}

	protected function isLineUnknown($event)
	{
		return true;
	}

	/********************** API MESSAGE TYPE CHECKERS **********************/

	protected function isApiAnswer($message)
	{
		return isset($message->type) && $message->type == 'answer';
	}

	protected function isApiPolarQuestion($message)
	{
		return isset($message->type) && $message->type == "polarQuestion";
	}

	protected function isApiMultipleChoiceQuestion($message)
	{
		return isset($message->type) && $message->type == "multipleChoiceQuestion";
	}

	protected function isApiExtendedContentsAnswer($message)
	{
		return isset($message->type) && $message->type == "extendedContentsAnswer";
	}

	protected function hasTextMessage($message) {
		return isset($message->message) && is_string($message->message);
	}


	/********************** LINE TO API MESSAGE DIGESTERS **********************/

	protected function digestFromLineText($event)
	{
		return array(
			'message' => $event->message->text
		);
	}

	protected function digestFromLinePostback($message)
	{
		if (is_string($message->postback->data) && explode('-', $message->postback->data)[0] === 'sessionSaved') {
			$postback = $this->session->get($message->postback->data);
		} else {
			$postback = $message->postback->data;
		}
		return json_decode($postback, true);
	}

	protected function digestFromLineSticker($event)
	{

		$stickerReplies = $this->conf['stickerReplies'];
		// If received sticker is in the list of available sticker return the same sticker
		// else return the 'unknown' configured sticker
		if (!in_array($event->message->packageId, $stickerReplies['availablePackages'])) {
			$unknowSticker = $stickerReplies['unknownStickerReplies'][array_rand($stickerReplies['unknownStickerReplies'])];
			$event->message->stickerId = $unknowSticker['stickerId'];
			$event->message->packageId = $unknowSticker['packageId'];
		}
		$sticker = $event->message;
		return array(
			'message' => $sticker,
			'skipBot' => true
		);
	}

	// Not processable message types
	protected function digestFromLineFile($message)
	{
		return $this->digestFromLineUnknown($message);
	}

	protected function digestFromLineImage($message)
	{
		return $this->digestFromLineUnknown($message);
	}

	protected function digestFromLineVideo($message)
	{
		return $this->digestFromLineUnknown($message);
	}

	protected function digestFromLineAudio($message)
	{
		return $this->digestFromLineUnknown($message);
	}

	protected function digestFromLineLocation($message)
	{
		return $this->digestFromLineUnknown($message);
	}

	protected function digestFromLineUnknown($event)
	{
		$message = $this->langManager->translate('unknownMessageType');
		return array(
			'message' => array(
				'type' => 'text',
				'text' => $message
			),
			'skipBot' => true
		);
	}


	/********************** CHATBOT API TO LINE MESSAGE DIGESTERS **********************/

	protected function digestFromApiAnswer($message)
	{
		$output = array();
		$urlButtonSetting = isset($this->conf['url_buttons']['attribute_name']) ? $this->conf['url_buttons']['attribute_name'] : '';

		if (strpos($message->message, '<img')) {
			// Handle a message that contains an image (<img> tag)
			$output['multiple_output'] = $this->handleMessageWithImages($message);
		} elseif (isset($message->attributes->$urlButtonSetting) && !empty($message->attributes->$urlButtonSetting)) {
			// Send a button that opens an URL
			$output = $this->buildUrlButtonMessage($message, $message->attributes->$urlButtonSetting);
		} else {
			// Add simple text-answer
			$output = [
				'type' => 'text',
				'text' => strip_tags($message->message)
			];
		}
		return $output;
	}

	protected function digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion)
	{
		$isMultiple = isset($message->flags) && in_array('multiple-options', $message->flags);
		$buttonTitleSetting = isset($this->conf['button_title']) ? $this->conf['button_title'] : '';

		foreach ($message->options as $option) {
			$actions []=
	            array(
	            	"type" => "postback",
					"label" => substr($isMultiple && isset($option->attributes->$buttonTitleSetting) ? $option->attributes->$buttonTitleSetting : $option->label, 0, 20),
					"displayText" => $isMultiple && isset($option->attributes->$buttonTitleSetting) ? $option->attributes->$buttonTitleSetting : $option->label,
					"data" => json_encode([
						"message" => $lastUserQuestion,
						"option" => $option->value
	            	])
				);
		}
        $output = [
        	"type" => "template",
        	"altText" => substr(strip_tags($message->message), 0, 400),
        	"template" => [
        		"type" => "buttons",
				"text" => strip_tags($message->message),
				"actions" => $actions
        	]
        ];
		return $output;

	}

	protected function digestFromApiPolarQuestion($message, $lastUserQuestion)
	{
		$items = array();
		foreach ($message->options as $option) {
			$actions []=
	            array(
	            	"type" => "postback",
					"label" => substr($this->langManager->translate($option->label), 0, 20),
					"displayText" => $this->langManager->translate($option->label),
					"data" => json_encode([
						"message" => $lastUserQuestion,
						"option" => $option->value
	            	])
				);
		}
        $output = [
        	"type" => "template",
        	"altText" => substr(strip_tags($message->message), 0, 400),
        	"template" => [
        		"type" => "buttons",
				"text" => strip_tags($message->message),
				"actions" => $actions
        	]
        ];
		return $output;
	}

    protected function digestFromApiExtendedContentsAnswer($message)
    {
        $buttonTitleSetting = isset($this->conf['button_title']) ? $this->conf['button_title'] : '';
        $count = 0;
		foreach ($message->subAnswers as $option) {
			// We need to save this rate code in session because line has a 300 characters limitation
			$this->session->set('sessionSaved-extendedContentAnswer' . $count,
				json_encode([
				 	"extendedContentAnswer" => $option
	         	])
			);
			$actions []=
	            array(
	            	"type" => "postback",
					"label" => substr(isset($option->attributes->$buttonTitleSetting) ? $option->attributes->$buttonTitleSetting : $option->message, 0 , 20),
					"data" => "sessionSaved-extendedContentAnswer" . $count
				);
			$count++;
		}
        $output = [
        	"type" => "template",
        	"altText" => substr(strip_tags($message->message), 0, 400),
        	"template" => [
        		"type" => "buttons",
				"text" => strip_tags($message->message),
				"actions" => $actions
        	]
        ];
		return $output;
    }

	/********************** MISC **********************/

	public function buildContentRatingsMessage($ratingOptions, $rateCode)
	{
		$count = 0;
		foreach ($ratingOptions as $option) {
			// We need to save this rate code in session because line has a 300 characters limitation
			$this->session->set('sessionSaved-rateCode' . $count,
				json_encode([
					'askRatingComment' => isset($option['comment']) && $option['comment'],
					'isNegativeRating' => isset($option['isNegative']) && $option['isNegative'],
					'ratingData' =>	[
						'type' => 'rate',
						'data' => array(
							'code' => $rateCode,
							'value'   => $option['id'],
							'comment' => null
						)
					]
				], true)
			);
			$items []=
	            array(
	            	"type" => "action",
					"action" => array(
		            	"type" => "postback",
						"label" => substr($this->langManager->translate( $option['label'] ), 0, 20),
						"displayText" => $this->langManager->translate( $option['label'] ),
						"data" => "sessionSaved-rateCode" . $count
					)
				);
			$count++;
		}
        $output = [
        	"type" => "text",
        	"text" => $this->langManager->translate('rate_content_intro'),
        	"quickReply" => array("items" => $items)
        ];
		return $output;
	}

	/**
	 *	Splits a message that contains an <img> tag into text/image/text and displays them in Line
	 */
	protected function handleMessageWithImages($message)
	{
		//Remove \t \n \r and HTML tags (keeping <img> tags)
		$text = str_replace(["\r\n", "\r", "\n", "\t"], '', strip_tags($message->message, "<img>"));
		//Capture all IMG tags and return an array with [text,imageURL,text,...]
		$parts = preg_split('/<\s*img.*?src\s*=\s*"(.+?)".*?\s*\/?>/', $text,-1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
		$output = array();
		for ($i = 0; $i < count($parts); $i++) {
			if(substr($parts[$i],0,4) == 'http'){
				$imgUrl = str_replace('http://', 'https://', $parts[$i]);
				$message = array(
					'type' => 'image',
					'originalContentUrl' => $imgUrl,
					'previewImageUrl' => $imgUrl
				);
			}else{
				$message = array(
					'type' => 'text',
					'text' => $parts[$i]
				);
			}
			$output[] = $message;
		}
		return $output;
	}

    /**
     *	Sends the text answer and displays an URL button
     */
    protected function buildUrlButtonMessage($message, $urlButton)
    {

        $buttonTitleProp = $this->conf['url_buttons']['button_title_var'];
        $buttonURLProp = $this->conf['url_buttons']['button_url_var'];

        if (!is_array($urlButton)) {
            $urlButton = [$urlButton];
        }

		$items = array();
		foreach ($urlButton as $button) {
			// If any of the urlButtons has any invalid/missing url or title, abort and send a simple text message
            if (!isset($button->$buttonURLProp) || !isset($button->$buttonTitleProp) || empty($button->$buttonURLProp) || empty($button->$buttonTitleProp)) {
                return [
		        	"type" => "text",
		        	"text" => strip_tags($message->message)
		        ];
            }
			$actions []=
	            array(
	            	"type" => "uri",
					"label" => substr($button->$buttonTitleProp, 0, 20),
					"uri" => $button->$buttonURLProp
				);
		}
        $output = [
        	"type" => "template",
        	"altText" => substr(strip_tags($message->message), 0, 400),
        	"template" => [
        		"type" => "buttons",
				"text" => strip_tags($message->message),
				"actions" => $actions
        	]
        ];
		return $output;
    }

    /**
     *	Sends escalation question message
     */
	public function buildEscalationMessage()
	{
		$items = array();
        $escalateOptions = [
            [
                "label" => 'yes',
                "escalate" => true
            ],
            [
                "label" => 'no',
                "escalate" => false
            ],
        ];
		foreach ($escalateOptions as $option) {
			$items []=
	            array(
	            	"type" => "action",
					"action" => array(
		            	"type" => "postback",
						"label" => $this->langManager->translate($option['label']),
						"displayText" => $this->langManager->translate($option['label']),
						"data" => json_encode([
                    		'escalateOption' => $option['escalate']
                		], true)
					)
				);
		}
        $output = [
        	"type" => "text",
        	"text" => $this->langManager->translate('ask_to_escalate'),
        	"quickReply" => array("items" => $items)
        ];
		return $output;
	}

}


