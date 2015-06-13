<?php

namespace Irap\CoreLibs;


/*
 * Library just for functions that generate html. E.g. generation of form fields and popular
 * includes etc.
 */

class HtmlGenerator
{
    /**
     * Generates the source link for the latest jquery source so that you dont have to remember 
     * it, or store it locally on your server and keep updating it.
     * @param void
     * @return html - the html for including jquery ui in your website.
     */
    public static function generate_jquery_include()
    {
        # This does not fetch version 1 but the latest 1.x version of jquery.
        $html = '<script type="text/javascript" ' .
                    'src="http://ajax.googleapis.com/ajax/libs/jquery/1/jquery.min.js">' .
                '</script>';
        return $html;
    }
    
    
    /**
     * Generates the source link for the latest jquery ui source so that you dont have to remember 
     * it, or store it locally on your server and keep updating it.
     * @param void
     * @return html - the html for including jquery ui in your website.
     */
    public static function generate_jquery_ui_include()
    {
        $html = '<script type="text/javascript" ' .
                    'src="http://ajax.googleapis.com/ajax/libs/jqueryui/1/jquery-ui.min.js" >' .
                '</script>';
        return $html;
    }


    /**
     * Generates the html for a hidden input field. This allows us to easily POST variables rather 
     * than using GET everywhere.
     * 
     * @param name - the neame of the variable we are trying to send.
     * @param value - the value of the variable we are posting.
     * 
     * @return the generated html for a hidden input field.
     */
    public static function generate_hidden_input_field($name, $value)
    {
        return "<input type='hidden' name='" . $name . "' value='" . $value . "' />";
    }


    /**
     * Generates an textfield (not textarea) that can be submitted by hitting return/enter.
     * 
     * @param label - the display text to put next to the input field.
     * @param name - the name/id of the generated input field.
     * @param onSubmit - javascript string of what to run when the buttons onClick is activated.
     * @param value - an optional parameter of the default/current value to stick in the input box.
     * 
     * @return htmlString - the generated html to create this submittable row.
     */
    public static function generate_submittable_textfield($fieldName,
                                                          $postfields  = array(),
                                                          $value       = "", 
                                                          $placeholder = '', 
                                                          $formId      = '')
    {    
        if ($formId != '')
        {
            $formId = ' id="' . $formId . '" ';
        }

        $htmlString = 
            '<form method="POST" action="" ' . $formId . '>' .
                self::generate_input_field($fieldName, 'text', $value, $placeholder) .
                self::generate_hidden_input_fields($postfields) .
                self::generate_submit_button('submit', $offscreen=true) .
            "</form>";

        return $htmlString;
    }


    /**
     * Generates what appears to be just a button but is actually a submittable form that will 
     * post itself or the specified postUrl. 
     * 
     * @param label      - the text to display on the button
     * @param postfields - name/value pairs of data to post when the form submits
     * @param postUrl    - optional address where the form should be posted.
     * 
     * @return html - the generated html for the button form.
     */
    public static function generate_button_form($label, $postfields, $postUrl='')
    {
        $html = '<form method="POST" action="' . $postUrl . '">' .
                    self::generate_hidden_input_fields($postfields) .
                    self::generate_submit_button($label) .
                '</form>';
        
        return $html;
    }


    /**
     * Generates an textfield (not textarea) that can be submitted by hitting return/enter.
     * 
     * @param label - the display text to put next to the input field.
     * @param name - the name/id of the generated input field.
     * @param onSubmit - javascript string of what to run when the buttons onClick is activated.
     * @param value - an optional parameter of the default/current value to stick in the input box.
     * 
     * @return htmlString - the generated html to create this submittable row.
     */
    public static function generate_ajax_textfield($fieldName,
                                                 $staticData   = array(),
                                                 $currentValue = "", 
                                                 $placeholder  = '',
                                                 $offscreenSubmit = true)
    {    
        $html = 
            "<form action='' onsubmit='ajaxPostForm(this, \"" . $fieldName . "\")'>" .
                self::generate_hidden_input_fields($staticData) .
                self::generate_input_field($fieldName, 'text', $currentValue, $placeholder) .
                self::generate_submit_button('', $offscreenSubmit) .
            '</form>';
        
        return $html;
    }


    /**
     * Generates a button that triggers an ajax request. (using POST and expecting json response)
     * 
     * @param label     - label to appear on the ajax button. e.g. 'click me'
     * @param postData - associative array of name/value pairs to send in the ajax request.
     * @param updateButtonText - flag for whether the buttons text should change to reflect status
     * @param onSuccess - name of javascript function to run upon successful request
     * @param onError   - name of javascript function to run if there was an ajax comms error.
     * @param onAlways  - name of javascript function to run if success or error.
     * 
     * @return html - the generated html for the ajax button.
    */
    public static function generate_ajax_button($label, 
                                                $postData, 
                                                $updateButtonText=true,
                                                $onSuccess = '', 
                                                $onError   = '', 
                                                $onAlways  = '')
    {
        $ajaxParams = array('data'     => $postData,
                            'type'     => 'POST',
                            'dataType' => $postData);
        
        $callbacks = '';
        
        if ($updateButtonText)
        {  
            $callbacks .= 
                'var originalText = this.value;' . PHP_EOL .
                'this.value = "Updating...";' . PHP_EOL .
                'ajaxRequest.fail(function(){this.value="Error"});' . PHP_EOL;
                'ajaxRequest.done(function(){this.value="Complete"});' . PHP_EOL;
                'var timeoutFunc = function(){this.value=originalText};' . PHP_EOL .
                'ajaxRequest.done(function(){setTimeout(timeoutFunc, 2000)});' . PHP_EOL;
        }
        
        if ($onSuccess != '')
        {
            $callbacks .= 'ajaxRequest.done(' . $onSuccess . ');' . PHP_EOL;
        }
        
        if ($onError != '')
        {
            $callbacks .= 'ajaxRequest.fail(' . $onError . ');' . PHP_EOL;
        }
        
        if ($onAlways != '')
        {
            $callbacks .= 'ajaxRequest.always(' . $onAlways . ');' . PHP_EOL;
        }
        
        # Important that only double quotes appear within onclick and no single quotes.
        $onclick = 
            'var ajaxUrl     = "ajax_handler.php";' . PHP_EOL .
            'var ajaxParams  = ' . json_encode($ajaxParams) . ';' . PHP_EOL .
            'var ajaxRequest = $.ajax(ajaxUrl, ajaxParams);' .  PHP_EOL .
            $callbacks;
        
        # Have to use an 'input type=button' rather than button here because we want to change  
        # value property
        $html = "<input type='button' value='" . $label . "' onclick='" . $onclick . "' />";
        
        return $html;
    }


   /**
    * Generates a html input field
    *
    * @param name - the name of the input field so we can retrieve the value with GET or POST
    * @param type - the type of the input field. e.g. text, password, checkbox, radio
     *              should not be 'submit' or 'button', use other funcs for those
    * @param currentValue - optional, if the textfield has a value already use that
    * @param placeholder - optional - specify the placeholder text
    * 
    * @return html - the generated html.
    */
    public static function generate_input_field($name, $type, $currentValue="", $placeholder="")
    {
        $type = strtolower($type);
        
        if ($type === 'button')
        {
            Core::throw_exception('Developer error: please use the generateButton function ' . 
                                 'instead to create buttons.');
        }
        
        if ($type == 'submit')
        {
            Core::throw_exception('Developer error: please use the generateSubmitButton function ' . 
                                 'instead to create submit buttons.');
        }
                
        $html = '<input ' .
                    'type="' . $type . '" ' .
                    'name="' . $name .'" ' . 
                    'placeholder="' . $placeholder . '" ' . 
                    'value="' . $currentValue . '" >';

        return $html;
    }


    /**
     * Generates a textaread form element
     * 
     * @param name          - the name of the textarea ( for reitrieving from POST)
     * @param currentValue  - any text that should appear in the texatrea
     * @param placeholder   - any text that should show in the textarea if there is no value
     * @param class         - the class to specify for the textarea (stylesheet)
     * @param rows          - the number of rows the textarea should have
     * @param cols          - the number of columns (width) the textarea should have
     * @param id            - if set the id will be set to this.
     * 
     * @return html - the generated html for the textarea. 
     */
    public static function generate_text_area($name, 
                                              $currentValue = "", 
                                              $placeholder  = "", 
                                              $class        = "",
                                              $rows         = "",
                                              $cols         = "",
                                              $id           = "",
                                              $disabled     = false)
    {
        $idAttribute = '';
        $rowAttribute = '';
        $colsAttribute = '';
        $disabledAttribute = '';
        
        if ($rows != "")
        {
            $rowAttribute = ' rows="' . $rows . '" ';
        }
        
        if ($cols != "")
        {
            $colsAttribute = ' cols="' . $cols . '" ';
        }
        
        
        if ($id != "")
        {
            $idAttribute = ' id="' . $id . '" ';
        }
        
        if ($disabled)
        {
            $disabledAttribute = ' disabled ';
        }
        
        $html = '<textarea ' .
                    'name="' . $name . '" ' .
                    'class="' . $class . '" ' .
                    'placeholder="' . $placeholder . '" ' .
                    $idAttribute .
                    $rowAttribute .
                    $colsAttribute .
                    $disabledAttribute .
                '>'  . 
                    $currentValue . 
                '</textarea>';
        
        return $html;
    }


    /**
     * Generates the html for a button which runs the provided javascript functionName when clicked.
     * This is a button element e.g. <button> and NOT an input type='button'
     * There are subtle differences, but the main one is that the text for a button is NOT changed
     * by changingt the .value but the .textContent attribute, and input type buttons are supposed
     * to be inside a form and will submit data with the form. Both can have an onclick.
     * 
     * @param name         - label to stick on the button, (what the user can see).
     * @param functionName - callback function to run when the button is clicked.
     * @param parameters  - an array of parameters to pass to the callback function.
     * @param confirm      - whether the user needs to confirm that they meant to click the button.
     * @param confMessage  - if confirm set to true, the confirmation message as it will appear.
     * 
     * @return htmlString - the html for the button.
     */
    public static function generate_button($label, 
                                           $functionName, 
                                           $parameters  = array(), 
                                           $confirm     = false, 
                                           $confMessage = "")
    {
        $parameterString = "";

        if (count($parameters) > 0)
        {
            foreach ($parameters as $parameter)
            {
                $literals = array('this', 'true', 'false');

                $lowerCaseParam = strtolower($parameter);

                # Handle special case where we want to pass the `this`, 'true' or 'false'.
                if (in_array($lowerCaseParam, $literals))
                {
                    $parameterString .= $parameter . ", ";
                }
                else
                {
                    $parameterString .= "'" . $parameter . "', ";
                }
            } 

            // Remove the last character which should be an excess ,
            $parameterString = substr($parameterString, 0, -2);
        }

        $onclick = $functionName . "(" . $parameterString . ")";

        if ($confirm)
        {
            $onclick = "if (confirm('" . $confMessage . "')){" . $onclick . "}";
        }

        $onclick = '"' . $onclick . '"';
        $htmlString = '<button onclick=' . $onclick . '>' . $label . '</button>';
        return $htmlString;
    }



    /**
     * Generates an input field row with a label beside it (making placeholder usage pointless).
     * This is useful for when you are displaying input fields with existing values. When this is 
     * the case, placeholders would not be visible, thus useless, but the user still needs to know 
     * what the fields represent.
     * 
     * @param name  - the name of the fild (name we use to get value from GET / POST)
     * @param type  - text, password, submit
     * @param label - the human readable name to display next to the input field.
     * @param value - the current value of the input field.
     * 
     * @return html - the generated html
     */
    public static function generate_input_field_row($name, $type, $label, $value="")
    {
        $html =
            "<div class ='row'>" .
                "<div class='label'>" . $label . "</div>" .
                "<div class='inputs'>" .
                    self::generate_input_field($name, $type, $value) .
                "</div>" .
            "</div>";

        return $html;
    }


    /**
     * Generates an html drop down menu for forms. If the array of drop down options passed in is
     * an array, then the value posted will be the key, and the display label for the option will be
     * the value.
     *
     * @param name           - name to assign to the input field (the lookup name when retrieving 
     *                          POST)
     * @param currentValue   - the current/default/selected value of that attribute.
     * @param options        - array of all the possible options/values that the user can pick
     *                          if this is an associative array, the key will be used as the value.
     * @param rowSize        - manually set the number of rows to show
     * @param multipleSelect - optional - set true if user should be able to select multiple values.
     * @param onChange       - specify javascript that should run when the dropdown changes. Note 
     *                          that this should not contain the text onchange= and if quotes are 
     *                          used (for js function parameters, then these should be encapsulated
     *                          in double quotes.
     * @param id             - (optional) set an id for the dropdown menu.
     *
     * @return htmlString - the generated html to be put on the page.
     */
    public static function generate_drop_down_menu($name, 
                                                   $currentValue, 
                                                   $options, 
                                                   $rowSize        = 1,
                                                   $multipleSelect = false,
                                                   $onChange       = "", 
                                                   $id             = "")
    {
        $isAssoc = self::isAssoc($options);

        $optionsHtml = "";

        foreach ($options as $key => $option)
        {   
            $optionValue = $option;
            $optionLabel = $option;

            if ($isAssoc)
            {
                $optionValue = $key;
            }

            $selectedAttribute = "";

            if ($optionValue == $currentValue)
            {
                $selectedAttribute = " selected='true' ";
            }

            $optionsHtml .= "<option " . $selectedAttribute . 
                                'value="' . $optionValue . '"' .
                            ">" . 
                                $optionLabel . 
                            "</option>" . PHP_EOL;
        }

        $nameAttribute      = " name='" . $name . "' ";
        $idAttribute = "";
        
        if ($id != "")
        {
            $idAttribute = " id='" . $name . "' ";
        }
        
        $sizeAttribute      = " size='" . $rowSize . "' ";
        $onChangeAttribute  = "";

        if ($onChange != "")
        {
            $onChangeAttribute = " onchange='" . $onChange . "' ";
        }

        $multipleAttribute  = "";

        if ($multipleSelect)
        {   
            $multipleAttribute = " multiple ";
        }

        $htmlString = 
            "<select" .
                $idAttribute . 
                $nameAttribute . 
                $sizeAttribute . 
                $onChangeAttribute . 
                $multipleAttribute . 
            ">" . 
                $optionsHtml . 
            "</select>";

        return $htmlString;
    }


    /**
     * Given an array of name/value pairs, this will generate all the hidden input fields for them
     * to be inserted into a form.
     * @param pairs - assoc array of name/value pairs to post
     * @return html - the generated html.
     */
    public static function generate_hidden_input_fields($pairs)
    {
        $html = '';
        
        foreach ($pairs as $name => $value)
        {
            $html .= self::generate_hidden_input_field($name, $value);
        }
        
        return $html;
    }



    /**
     * Generates a submit button for a form.
     * 
     * @param label - The text that will be displayed over the button
     * @param offscreen - render the submit button offscreen so that it does not appear within the
     *                    form, but allows the form to be submitted by hitting enter. Setting
     *                    display:none would work in FF but not chrome
     * 
     * @return html - The html code for the button
     */
    public static function generate_submit_button($label="Submit", $offscreen=false)
    {
        $styleAttribute = '';
        
        if ($offscreen)
        {
            $styleAttribute = ' style="position: absolute; left: -9999px" ';
        }

        $html = '<input ' .
                    'type="submit" ' .
                    'value="' . $label . '" ' .
                     $styleAttribute . 
                '/>'; 
        
        return $html;
    }


    /**
    * Effectively generates a normal link, but rendered as a button. e.g <a href=''>; The main 
    * advantage is that you can specify a confirm message or create a better/different look.
    * Note that this will be a form submit button and not a javascript button.
    * 
    * @param label       - the label to appear on the button.
    * @param location    - where you want the link to go
    * @param confirm     - set to true if you want a confirm dialogue to confirm.
    * @param confMessage - if confirm set to true, this will be the message that is displayed.
    * 
    * @return html - the generated html for the button.
    */
    public static function create_button_link($label, $location, $confirm=false, $confMessage="")
    {
        $confirmAttribute = "";

        if ($confirm)
        {
            $onclick = "return confirm('" . $confMessage . "')";
            $confirmAttribute = 'onsubmit="' . $onclick . '"';
        }

        $html = 
            '<form method="post" action="' . $location . '" ' . $confirmAttribute . '>' .
                self::generate_submit_button($label) .
            '</form>';

        return $html;
    }
}
