### OBJECTIVE

This Line connector extends from the [Chatbot API Connector](https://github.com/inbenta-integrations/chatbot_api_connector) library. This library includes a Line API Client in order to send messages to Line users through our Line Page. It translates Line's messages into the Inbenta Chatbot API format and vice versa. Also, it implements some methods from the base HyperChat client in order to communicate with Line when the user is chatting.

### FUNCTIONALITIES
This connector inherits the functionalities from the `ChatbotConnector` library. Currently, the features provided by this application are:

* Simple answers
* Multiple options with a limit of 3 elements (Line only allows a maximum number of three options)
* Polar questions
* Chained answers
* Content ratings (yes/no + comment)
* Escalate to HyperChat after a number of no-results answers
* Escalate to HyperChat when matching with an 'Escalation FAQ'
* Send information to webhook through forms

### HOW TO CUSTOMIZE

**Custom Behaviors**

If you need to customize the bot flow, you need to modify the class `LineConnector.php`. This class extends from the ChatbotConnector and here you can override all the parent methods.


### STRUCTURE

The `LineConnector` folder has some classes needed to use the ChatbotConnector with Line. These classes are used in the LineConnector constructor in order to provide the application with the components needed to send information to Line and to parse messages between Line, ChatbotAPI and HyperChat.

**External API folder**

Inside this folder there is the Line API client which allows the bot to send messages and handle authorization events.


**External Digester folder**

This folder contains the class LineDigester. This class is a kind of "translator" between the Chatbot API and Line. Mainly, the work performed by this class is to convert a message from the Chatbot API into a message accepted by the Line API. It also does the inverse work, translating messages from Line into the format required by the Chatbot API.