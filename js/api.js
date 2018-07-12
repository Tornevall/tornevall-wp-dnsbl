$T_DNSBL = jQuery.noConflict();

function torneDnsblApi(req, callback, postdata) {
    var definedMethod = "POST";
    // This is probably useless
    if (typeof arguments[3] != "undefined") {
        definedMethod = arguments[3];
    }
    $T_DNSBL.ajax(
        {
            url: tornevall_dnsbl_vars.ajax_url,
            method: definedMethod,
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


function runApiTest(requestFunction) {
    $T_DNSBL('#apiTestResponse').html('<img src="' + tornevall_dnsbl_vars.spinner + '">');
    $T_DNSBL('#apiTestResponse').show();
    if (requestFunction == "test") {
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
    } else if (requestFunction == "flags") {
        torneDnsblApi("dnsbl", function (a) {
            if (typeof a["response"] != "undefined") {
                $T_DNSBL('#apiTestResponse').html('<span style="font-weight: bold;color:#009900;">Flags updated</span>');
            } else {
                $T_DNSBL('#apiTestResponse').html('<span style="font-weight: bold;color:#990000;">Request failure</span>');
            }
        }, {
            "verb": "getFlags"
        });
    }
}

function tFindDnsblAddr() {
    $T_DNSBL('#delistingTestStatus').html('<img src="' + tornevall_dnsbl_vars.spinner + '">');
    $T_DNSBL('#delistingTestStatus').show();
    torneDnsblApi("dnsbl", function (a) {
            if (typeof a["errorcode"] != "undefined" && a["errorcode"] >= 400) {
                $T_DNSBL('#delistingTestStatus').html('<div style="margin-top: 3px;padding:5px;color:#990000;border:1px solid #FF0000;background: #F0B0B0">' + a["errorstring"] + '</div>');
            } else {
                if (typeof a["response"] != "undefined") {

                    var listedResponse = "";
                    var requestResponse = a["response"]["requestResponse"];
                    for (requestIndex = 0; requestIndex < requestResponse.length; requestIndex++) {
                        var ipAddr = requestResponse[requestIndex]["ip"];
                        var constants = requestResponse[requestIndex]["constants"].join("<br>");
                        var delDateTime = requestResponse[requestIndex]["deleted"];
                        var isActive = "";
                        if (delDateTime == "0000-00-00 00:00:00" || null == delDateTime) {
                            isActive = "Listed";
                            isActiveColor = "#990000";
                        } else {
                            isActive = "Removed " + delDateTime;
                            isActiveColor = "#009900";
                        }
                        listedResponse += '<div style="cursor: pointer;font-weight:bold;color:' + isActiveColor + '" title="' + constants + '" onclick="$T_DNSBL(\'#dnsbl_ip_flags_' + requestIndex + '\').show()">' + ipAddr + ": " + isActive + '<div id="dnsbl_ip_flags_' + requestIndex + '" style="display: none;color:#000000 !important;">' + constants + '</div></div>';
                    }

                    $T_DNSBL('#delistingTestStatus').html('<div style="margin-top: 3px;padding:5px; color:#990000;border:1px solid #FF0000;background: #FFFFE0">' + listedResponse + '</div>');

                }
            }
        }, {
            "verb": "request",
            "ip": $T_DNSBL('#findIpAddr').val(),
            "n": $T_DNSBL('#dNonce').val()
        }
    );
}