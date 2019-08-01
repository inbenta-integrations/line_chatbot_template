<?php

//Inbenta Chatbot configuration
return array(
	"default" => array(
	    "answers" => array(
	        "sideBubbleAttributes"  => array(),
	        "answerAttributes"      => array(
	            "ANSWER_TEXT",
	        ),
	        "maxOptions"            => 3,
	        "maxRelatedContents"    => 2
	    ),
	    "forms" => array(
	        "allowUserToAbandonForm"    => true,
	        "errorRetries"              => 2
	    ),
	    "lang"  => "en"
	),
	"user_type" => 0,
	"content_ratings" => array(		// Remember that these ratings need to be created in your instance
		"enabled" => true,
		"sticker" => false,
		"ratings" => array(
			array(
	           'id' => 1,
	           'label' => 'yes',
	           'comment' => false,
	           'isNegative' => false
	       ),
			array(
	           'id' => 2,
	           'label' => 'no',
	           'comment' => true, 	// Whether clicking this option should ask for a comment
	           'isNegative' => true
	       )
		)
	),
	"digester" => array(
		"button_title" => "CONNECTOR_TITLE_IN_BUTTON", 			// Provide the attribute that contains the custom content-title to be displayed in multiple options
		"url_buttons" => array(
			"attribute_name" 	=> "CONNECTOR_URL_BUTTON",	// Provide the setting that contains an url+title to be displayed as URL button
			"button_title_var" 	=> "CONNECTOR_BUTTON_TITLE",  // Provide the property name that contains the button title in the button object
			"button_url_var"	=> "CONNECTOR_BUTTON_URL", 	// Provide the property name that contains the button URL in the button object
		),
		"stickerReplies" => array(
			// Official packages ID according to: https://developers.line.biz/media/messaging-api/sticker_list.pdf
			"availablePackages" => array('11537', '11538', '11539'),
			// Answer stickers in case the sticker received is not in the list of official stickers
			"unknownStickerReplies" => array(
				// Example stickers:
				array('packageId' => '11537', 'stickerId' => '52002744'),
				array('packageId' => '11538', 'stickerId' => '51626506'),
				array('packageId' => '11539', 'stickerId' => '52114129')
			),
			"thanksRatingStickers" => array(
				// Example stickers:
				array('packageId' => '11537', 'stickerId' => '52002739'),
				array('packageId' => '11539', 'stickerId' => '52114110')
			)
	    )
	)
);