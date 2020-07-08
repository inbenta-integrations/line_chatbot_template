### OBJECTIVE
This template has been implemented in order to develop Line bots that consume from the Inbenta Chatbot API with the minimum configuration and effort. It uses some libraries to connect the Chatbot API with Line. The main library of this template is Line Connector, which extends from a base library named [Chatbot API Connector](https://github.com/inbenta-integrations/chatbot_api_connector), built to be used as a base for different external services like Skype, Line, etc.

This template includes **/conf** and **/lang** folders, which have all the configuration and translations required by the libraries, and a small file **server.php** which creates a LineConnector’s instance in order to handle all the incoming requests.

### FUNCTIONALITIES
This bot template inherits the functionalities from the `ChatbotConnector` library. Currently, the features provided by this application are:

* Simple answers
* Multiple options
* Polar questions
* Chained answers
* Content ratings (yes/no + comment)
* Trigger Line's Switching functionality after a number of no-results answers
* Trigger Line's Switching functionality after after a number of negative ratings
* Trigger Line's Switching functionality when matching with an 'Escalation FAQ'
* Send information to webhook through forms
* Custom FAQ title in button when displaying multiple options
* Retrieve Line tokens from ExtraInfo
* Send a button that opens a configured URL along with the answer

### INSTALLATION
It's pretty simple to get this UI working. The mandatory configuration files are included by default in `/conf/custom` to be filled in, so you have to provide the information required in these files:

* **File 'api.php'**
    Provide the API Key and API Secret of your Chatbot Instance.

* **File 'environments.php'**
    Here you can define regexes to detect `development` and `preproduction` environments. If the regexes do not match the current conditions or there isn't any regex configured, `production` environment will be assumed.

Also, this template needs the Line App `Channel ID` and `Channel Secret` that will be retrieved from ExtraInfo. Here are the steps to create the full ExtraInfo object:

* Go to **Knowledge -> Extra Info -> Manage groups and types** and click on **Add new group**. Name it `line`.
* Go to **Manage groups and types -> line -> Add type**. Name it `line_conf`.
* Add 3 new properties named `development`, `preproduction` and `production` of type *multiple*.
* Inside each of those 3 properties, create the following sub-properties:
	- `channel_id` (*required*): Id of the Line's Channel where messages and event will be sent to.
	- `channel_secret` (*required*): Secret of the Line's Channel. The Id and Secret will be used to generate an access token.
	- `switcher_destination` (*if using Line's Switcher API*) - Identifies the destination where the messages will be sent to after Line's Switch capability has been triggered (this webhook will recive no more events until Line's Switch returns to it's original status).
	- `switcher_secret` (*optional*) - For auth purposes in the _switcher_destination_.
	- `service_code` (*optional*) - For Line statistics.

Now, create the ExtraInfo objects by clicking the **New entry** button:
* Name the entry `line_conf`.
* Select the group ‘line’ and the type line_conf.
* Insert the Line's channel ID/Secret that you can find in your Line's App "Settings" tab.

Note that you can have different Line App configuration set in for the different environments: development, preproduction or production.

Remember to publish the new ExtraInfo by clicking the **Post** button.

### HOW TO CUSTOMIZE
**From configuration**

For a default behavior, the only requisite is to fill the basic configuration (more information in `/conf/README.md`). There are some extra configuration parameters in the configuration files that allow you to modify the basic-behavior.


**Custom Behaviors**

If you need to customize the bot flow, you need to extend the class `LineConnector`, included in the `/lib/LineConnector` folder. You can modify 'LineConnector' methods and override all the parent methods from `ChatbotConnector`.

For example, when the bot is configured to escalate with an agent, a conversation in HyperChat starts. If your bot needs to use an external chat service, you should override the parent method `escalateToAgent` and set up the external service:
```php
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
			} else {
				$this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('error_creating_chat')));
			}
		} else {
			// Use the parent method to escalate to HyperChat
			parent::escalateToAgent();
		}
	}
```


**Line's Switching API**

To start using Line's Switching API capability set the chat configuration at `/conf/custom/chat.php` to **enabled** and **useExternal** (use chat service other than Inbenta's Hyperchat) to *true*:

```php
// Line switch chat configuration
return array(
    'chat' => array(
    	'enabled' => true, // Set to true to enable chat
    	'useExternal' => true // Set to true to use Line Switcher API
    ),
    'triesBeforeEscalation' => 2,
    'negativeRatingsBeforeEscalation' => 2
);
```

Also, **`switcher_destination`** must have been set in the chatbot instance **extraInfo** for the switcher to know where to send the events after the switching has been triggered.

**Switching trigger by no-result answer and negative content rating**

Configuration parameter `triesBeforeEscalation` sets the number of no-results answers after which the bot should trigger the switching process. Parameter `negativeRatingsBeforeEscalation` sets the number of negative ratings after which the bot should escalate to an agent.


**Switching trigger with FAQ**

The specific FAQ content needs to meet a few requisites to trigger the switching process:
- Dynamic setting named `ESCALATE`, non-indexable, visible, `Text` box-type with `Allow multiple objects` option checked
- In the content, add a new object to the `Escalate` setting (with the plus sign near the setting name) and type the text `TRUE`.

After a Restart Project Edit and Sync & Restart Project Live, your bot should escalate when this FAQ is matched.
Note that the `server.php` file has to be subscribed to the required HyperChat events as described in the previous section.

### DEPENDENCIES
This application uses these dependencies loaded through Composer:
* [Inbenta's Chatbot API connector](https://github.com/inbenta-integrations/chatbot_api_connector)

It also uses the Line's API v2. You can find more information in the [official Line documentation](https://developers.line.biz/en/docs/messaging-api/).
