// Keeping this uncompressed - 201027

$T_DNSBL = jQuery.noConflict();
var flagIndex;

/**
 * API function caller
 * @param req
 * @param callback
 * @param postdata
 */
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

/**
 * Test the api
 * @param requestFunction
 */
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
                        $T_DNSBL('#apiTestResponse').html('<span style="font-weight: bold;color:#ff9900;">' + tornevall_dnsbl_vars.tr_api_reply_success + ': ' + a["response"]["returnRandomResponse"] + '</span>');
                    } else {
                        var validationResponse = a["response"]["keyResponse"]["appClientData"];
                        $T_DNSBL('#apiTestResponse').html('<span style="font-weight: bold;color:#009900;">' + tornevall_dnsbl_vars.tr_api_reply_authorized + ': ' + (validationResponse["AUTHORIZED"] == "1" ? "OK<br>" + tornevall_dnsbl_vars.saveConfigNotice : tornevall_dnsbl_vars.tr_api_reply_fail) + "</span>");
                    }
                }
            }
        }, {
            "verb": $T_DNSBL('#tornevall_dnsbl_api_key').val() != "" ? "key" : "returnRandom",
            "application": $T_DNSBL('#tornevall_dnsbl_api_id').val(),
            "authKey": $T_DNSBL('#tornevall_dnsbl_api_key').val()
        });
    } else if (requestFunction == "flags") {
        torneDnsblApi("dnsbl", function (a) {
            if (typeof a["response"] != "undefined") {
                $T_DNSBL('#apiTestResponse').html('<span style="font-weight: bold;color:#009900;">' + tornevall_dnsbl_vars.tr_flags_updated + '</span>');
            } else {
                $T_DNSBL('#apiTestResponse').html('<span style="font-weight: bold;color:#990000;">' + tornevall_dnsbl_vars.tr_request_failure + '</span>');
                $T_DNSBL('#apiTestResponse').html('<span style="font-weight: bold;color:#990000;">' + tornevall_dnsbl_vars.tr_request_failure + '</span>');
            }
        }, {
            "verb": "getFlags"
        });
    }
}

/**
 * Scan for a listed ip
 */
function tFindDnsblAddr() {
    $T_DNSBL('#delistingTestStatus').html('<img src="' + tornevall_dnsbl_vars.spinner + '">');
    $T_DNSBL('#delistingTestStatus').show();
    if ($T_DNSBL('#findIpAddr').val() == "") {
        $T_DNSBL('#delistingTestStatus').html(tornevall_dnsbl_vars.tr_no_empty_value);
        return;
    }

    var requestFlags = [""];
    if (tornevall_dnsbl_vars.tornevall_dnsbl_getlisted_resolver == "1") {
        requestFlags = ["resolve"];
    }

    torneDnsblApi("dnsbl", function (a) {
        if (typeof a["errorcode"] != "undefined" && a["errorcode"] >= 400) {
            $T_DNSBL('#delistingTestStatus').html('<div style="margin-top: 3px;padding:5px;color:#990000;border:1px solid #FF0000;background: #F0B0B0">' + a["errorstring"] + '</div>');
        } else {
            if (typeof a["response"] != "undefined") {
                var listedResponse = "";
                var requestResponse = a["response"]["requestResponse"];
                var typebit = null;
                var ipAddr = null;
                var delistTestBorder;

                for (requestIndex = 0; requestIndex < requestResponse.length; requestIndex++) {
                    ipAddr = requestResponse[requestIndex]["ip"];
                    typebit = requestResponse[requestIndex]["typebit"];

                    if (null != typebit) {
                        var constants = requestResponse[requestIndex]["constants"].join("<br>");
                        var constantsTitle = requestResponse[requestIndex]["constants"].join("\n");
                        var delDateTime = requestResponse[requestIndex]["deleted"];
                        var isActive = "";
                        var resolverAddr;
                        if (requestResponse[requestIndex]["hasResolveFlag"] == "1") {
                            resolverAddr = requestResponse[requestIndex]["resolve"];
                            if (resolverAddr != "" && ipAddr != resolverAddr) {
                                if (resolverAddr != requestResponse[requestIndex]["lastResolve"]) {
                                    resolverAddr += " / " + requestResponse[requestIndex]["lastResolve"];
                                }
                                ipAddr += " (" + resolverAddr + ")";
                            }
                        } else {
                            resolverAddr = null;
                        }
                        if (delDateTime == "0000-00-00 00:00:00" || null == delDateTime) {
                            isActive = tornevall_dnsbl_vars.tr_blacklisted;
                            isActiveColor = "#990000";
                            delistTestBorder = "#990000";
                        } else {
                            isActive = tornevall_dnsbl_vars.tr_removed + " " + delDateTime;
                            isActiveColor = "#009900";
                            delistTestBorder = "#009900";
                        }
                        listedResponse += '<div style="font-weight:bold;color:' + isActiveColor + '" title="' + constantsTitle + '">';
                        listedResponse += '     <span onclick="$T_DNSBL(\'#dnsbl_ip_flags_' + requestIndex + '\').show()">';
                        listedResponse += '         <img style="vertical-align: middle;" src="' + tornevall_dnsbl_vars.q + '">';
                        listedResponse += '     </span>';
                        if (requestResponse.length == 1) {
                            listedResponse += '     <span onclick="setDelistAddr(\'' + ipAddr + '\', ' + requestIndex + ')">';
                            listedResponse += '         <img style="vertical-align: middle;" src="' + tornevall_dnsbl_vars.d + '">';
                            listedResponse += '     </span>';
                        }
                        listedResponse += ipAddr + ": " + isActive;
                        listedResponse += '     <div id="dnsbl_ip_flags_' + requestIndex + '" style="display: none;color:#000000 !important;">' + constants + '</div>';
                        listedResponse += '</div>';
                    } else {
                        isActiveColor = '#009999';
                        delistTestBorder = "#009999";
                        listedResponse += '<div style="cursor: pointer;font-weight:bold;color:' + isActiveColor + '" title="' + constants + '">' + ipAddr + ': ' + tornevall_dnsbl_vars.tr_not_blacklisted + '</div>';
                    }
                }

                $T_DNSBL('#delistingTestStatus').html('<div style="margin-top: 3px;padding:5px; color:#990000;border:1px solid ' + delistTestBorder + ';background: #FFFFE0">' + listedResponse + '</div>');
            }
        }
    }, {
        "verb": "request",
        "ip": $T_DNSBL('#findIpAddr').val(),
        "n": $T_DNSBL('#dNonce').val(),
        "flags": requestFlags
    });
}

/**
 * Request delisting with captcha request
 *
 * @param addr
 * @param index
 */
function setDelistAddr(addr, index) {
    flagIndex = index;
    torneDnsblApi("captcha", function (a) {
        if (typeof a["response"] !== "undefined" && a["response"]["getCaptchaResponse"] !== "undefined") {
            var captchaResponse = a["response"]["getCaptchaResponse"];
            var imageHash = captchaResponse["imageHash"];
            var imageUrl = captchaResponse["imageUrl"];
            var capForm = '<form onsubmit="return false;">' +
                '<input type="hidden" value="' + imageHash + '" id="meHash">' +
                '<img src="' + imageUrl + '"><br>' +
                '<b>' + tornevall_dnsbl_vars.tr_captcha_image + '</b>' +
                '<input type="text" vaue="" id="meImage"><br>' +
                '<input type="button" value="Submit" onclick="setDelistCaptcha()">' +
                '</form>';
            $T_DNSBL('#dnsbl_ip_flags_' + index).html(capForm);
            $T_DNSBL('#dnsbl_ip_flags_' + index).show();
        }
    }, {
        "verb": "getCaptcha",
        "n": tornevall_dnsbl_vars.dnsbln
    });
}

function setDelistCaptcha() {
    var meString = $T_DNSBL('#meImage').val();
    var meHash = $T_DNSBL('#meHash').val();
    var fIp = $T_DNSBL('#findIpAddr').val();

    if (meString != "" && meHash != "") {
        torneDnsblApi("captcha", function (a) {
            if (typeof a["response"] != "undefined" && typeof a["response"]["testCaptchaResponse"] != "undefined") {
                if (a["response"]["testCaptchaResponse"] == "1") {
                    torneDnsblApi("dnsbl", function (a) {
                        if (a["errorstring"] != "") {
                            $T_DNSBL('#dnsbl_ip_flags_' + flagIndex).html(a["errorstring"]);
                        } else if (typeof a["response"] != "undefined" && typeof a["response"]["dnsblResponse"] != "undefined") {
                            var dnsblResponse = a["response"]["dnsblResponse"];
                            if (typeof dnsblResponse["status"]["0"] != "undefined") {
                                var firstIpStatus = dnsblResponse["status"]["0"];
                                var deleteString = "";
                                if (firstIpStatus["state"] == "delete") {
                                    deleteString = firstIpStatus["addr"] + ": " + tornevall_dnsbl_vars.tr_delist_success;

                                    if (typeof firstIpStatus["penalties"]["removalCount"] != "undefined") {
                                        deleteString += " " + tornevall_dnsbl_vars.tr_delist_penalties + "<br>";
                                        deleteString += tornevall_dnsbl_vars.tr_delist_extended + firstIpStatus["penalties"]["removaltime"];
                                    }

                                    $T_DNSBL('#dnsbl_ip_flags_' + flagIndex).html(deleteString);
                                }
                            }
                        }
                    }, {
                        "verb": "",
                        "ip": fIp,
                        "n": tornevall_dnsbl_vars.dnsbln,
                        "method": "delete"
                    });
                }
            } else {
                if (a["errorstring"] != "") {
                    $T_DNSBL('#dnsbl_ip_flags_' + flagIndex).html(a["errorstring"]);
                }
            }
        }, {
            "verb": "testCaptcha",
            "hash": meHash,
            "response": meString
        });
    }
}

function findIpAddrPress(e) {
    var charCode = (typeof e.which === "number") ? e.which : e.keyCode;
    if (charCode == 13) {
        tFindDnsblAddr();
    }
}
