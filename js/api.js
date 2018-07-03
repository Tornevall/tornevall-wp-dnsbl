$T_DNSBL = jQuery.noConflict();

function torneDnsblApi(req, callback, postdata) {
    $T_DNSBL.ajax(
        {
            url: tornevall_dnsbl_vars.ajax_url,
            method: "POST",
            data: {
                'action': 'tornednsbl',
                'request': req,
                'postdata': postdata,
                'n': tornevall_dnsbl_vars.dnsbln
            }
        }
    ).done(function (a) {
        if (typeof callback == "function" && typeof a.response !== "undefined") {
            callback(a);
        }
    });
}


function runApiTest() {
    $T_DNSBL('#apiTestResponse').html('<img src="' + tornevall_dnsbl_vars.spinner + '">');
    $T_DNSBL('#apiTestResponse').show();
    torneDnsblApi("test", function (a) {
            if (typeof a["errorstring"] && a["errorstring"] != "") {
                $T_DNSBL('#apiTestResponse').html(a["errorstring"]);
            } else {
                if (typeof a["response"] != "") {
                    if (typeof a["response"]["returnRandomResponse"] != "undefined") {
                        $T_DNSBL('#apiTestResponse').html('<span style="font-weight: bold;color:#ff9900;">API replied successfully: ' + a["response"]["returnRandomResponse"] + '</span>');
                    } else {
                        var validationResponse = a["response"]["keyResponse"]["appClientData"];
                        $T_DNSBL('#apiTestResponse').html('<span style="font-weight: bold;color:#009900;">API Authorized Response: ' + (validationResponse["AUTHORIZED"] == "1" ? "yes<br>" + tornevall_dnsbl_vars.saveConfigNotice : "no") + "</span>");
                    }
                }
            }
        }, {
            "verb": $T_DNSBL('#tornevall_dnsbl_api_key').val() != "" ? "key" : "returnRandom",
            "application": $T_DNSBL('#tornevall_dnsbl_api_id').val(),
            "authKey": $T_DNSBL('#tornevall_dnsbl_api_key').val()
        }
    );
}
