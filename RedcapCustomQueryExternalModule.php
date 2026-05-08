<?php

namespace OxfordVaccineGroup\RedcapCustomQueryExternalModule;

use ExternalModules\AbstractExternalModule;
use REDCap;

class RedcapCustomQueryExternalModule extends AbstractExternalModule
{
    /**
     * Add the custom query icon to fields tagged with @CUSTOMQUERY on data entry forms.
     */
    public function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance)
    {
        global $Proj;

        if (!$this->currentUserCanUseCustomQueries()) {
            return;
        }

        $queryAjaxUrl = $this->getUrl('CustomQueryAjax.php');
        $recordId = $record ?? '';
        $repeatInstrument = ($Proj !== null && $Proj->isRepeatingForm($event_id, $instrument)) ? $instrument : '';

        $instrumentFields = REDCap::getFieldNames($instrument);
        $fieldsAttributes = REDCap::getDataDictionary('array', false, $instrumentFields);
        $customQueryFieldNames = [];

        foreach ($fieldsAttributes as $fieldAttribute) {
            $fieldAnnotation = $fieldAttribute['field_annotation'] ?? ($fieldAttribute['misc'] ?? '');
            if (stripos($fieldAnnotation, '@CUSTOMQUERY') === false) {
                continue;
            }

            $fieldName = $fieldAttribute['field_name'] ?? '';
            if ($fieldName !== '') {
                $customQueryFieldNames[] = $fieldName;
            }
        }

        if (empty($customQueryFieldNames)) {
            return;
        }

        $this->renderClientAssetsOnce();

        foreach ($customQueryFieldNames as $fieldName) {
            $queryContext = [
                'ajaxUrl' => $queryAjaxUrl,
                'record' => (string) $recordId,
                'eventId' => (int) $event_id,
                'fieldName' => $fieldName,
                'instrument' => $instrument,
                'repeatInstance' => (int) $repeat_instance,
                'repeatInstrument' => $repeatInstrument,
                'hasSavedRecord' => ($recordId !== ''),
            ];

            $this->renderCustomQueryFieldScript($fieldName, $fieldName . '-tr', $queryContext);
        }
    }

    /**
     * Access is controlled only by the module's configured REDCap roles.
     */
    public function currentUserCanUseCustomQueries()
    {
        if (!defined('USERID') || USERID === '') {
            return false;
        }

        $allowedRoleIds = $this->getAllowedCustomQueryRoleIds();
        if (empty($allowedRoleIds)) {
            return false;
        }

        $userRights = REDCap::getUserRights();
        $currentUserRights = $userRights[USERID] ?? [];
        $currentRoleId = (string) ($currentUserRights['role_id'] ?? '');

        return ($currentRoleId !== '' && in_array($currentRoleId, $allowedRoleIds, true));
    }

    /**
     * Return configured REDCap role ids as strings for stable comparisons.
     */
    public function getAllowedCustomQueryRoleIds()
    {
        $configuredRoles = (array) $this->getProjectSetting('custom_query_roles_allowed');
        $configuredRoles = array_map('strval', $configuredRoles);

        return array_values(array_filter($configuredRoles, 'strlen'));
    }

    /**
     * Resolve the current REDCap user to the numeric ui_id used in query history rows.
     */
    public function getCurrentUserUiId()
    {
        if (!defined('USERID') || USERID === '') {
            return null;
        }

        $sql = "select ui_id from redcap_user_information where username = ?";
        $result = db_query($sql, [USERID]);
        if ($result && ($row = db_fetch_assoc($result))) {
            return (int) $row['ui_id'];
        }

        return null;
    }

    /**
     * Render the shared custom query CSS once, even when multiple fields on the form
     * are tagged with @CUSTOMQUERY.
     */
    private function renderClientAssetsOnce()
    {
        static $assetsRendered = false;
        if ($assetsRendered) {
            return;
        }

        $assetsRendered = true;

        echo <<<HTML
<style>
    .custom-query-button-slot {
        display: block;
        margin-top: 2px;
        text-align: right;
        line-height: 0;
        font-size: 0;
    }

    .custom-query-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 1px;
        margin: 0 0 1px;
        border: 0;
        background: transparent;
        box-shadow: none;
        cursor: pointer;
        line-height: 1;
        opacity: 0.8;
        position: relative;
        left: 2px;
        vertical-align: top;
    }

    .custom-query-button:hover,
    .custom-query-button:focus {
        outline: none;
        opacity: 1;
        background: #fff5b8;
        box-shadow: inset 0 0 0 1px #3399ff;
        border-radius: 1px;
    }

    .custom-query-button.has-history {
        opacity: 1;
    }

    .custom-query-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 16px;
        height: 16px;
        border-radius: 999px;
        border: 1px solid rgba(16, 42, 67, 0.18);
        box-sizing: border-box;
        background: #7b8794;
        color: #ffffff;
        font-size: 8px;
        font-weight: 700;
        line-height: 1;
        letter-spacing: 0;
        text-transform: uppercase;
        font-family: Arial, sans-serif;
        flex-shrink: 0;
        vertical-align: top;
    }

    .custom-query-button-badge {
        width: 16px;
        height: 16px;
        font-size: 8px;
    }

    .custom-query-badge.is-new {
        background: #7b8794;
    }

    .custom-query-badge.is-open-awaiting {
        background: #c00000;
    }

    .custom-query-badge.is-open-responded {
        background: #1f4f8a;
    }

    .custom-query-badge.is-closed {
        background: #2f855a;
    }

    .custom-query-badge.is-verified {
        background: #0f766e;
    }

    .custom-query-badge.is-deverified {
        background: #d64545;
    }

    .custom-query-badge.is-default {
        background: #486581;
    }

    .custom-query-shell {
        font-size: 13px;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .custom-query-modal-expanded .custom-query-shell {
        height: 100%;
        min-height: 0;
    }

    .custom-query-summary-card,
    .custom-query-form-card {
        padding: 12px;
        border: 1px solid #d7dee8;
        border-radius: 6px;
        background: #f8fafc;
        flex-shrink: 0;
    }

    .custom-query-summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 10px;
    }

    .custom-query-header-card {
        padding: 10px 12px;
    }

    .custom-query-header-card .custom-query-summary-grid {
        gap: 8px 12px;
    }

    .custom-query-header-card .custom-query-label {
        margin-bottom: 2px;
    }

    .custom-query-summary-value {
        line-height: 1.25;
    }

    .custom-query-summary-secondary {
        display: block;
        margin-top: 2px;
        font-size: 12px;
        line-height: 1.25;
        color: #52606d;
    }

    .custom-query-header-card .custom-query-status-pill {
        padding: 3px 8px;
    }

    .custom-query-label {
        margin-bottom: 4px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #52606d;
    }

    .custom-query-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 8px;
        border-radius: 999px;
        background: #eef2f6;
        color: #243b53;
        font-weight: 600;
    }

    .custom-query-status-pill img {
        width: 16px;
        height: 16px;
        flex-shrink: 0;
    }

    .custom-query-history-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        padding-right: 4px;
    }

    .custom-query-history-card {
        display: flex;
        flex-direction: column;
        flex: 0 0 200px;
        height: 200px;
        min-height: 200px;
        overflow: hidden;
    }

    .custom-query-modal-expanded .custom-query-history-card {
        flex: 1 1 auto;
        height: auto;
        min-height: 0;
    }

    .custom-query-entry {
        flex: 0 0 auto;
    }

    .custom-query-entry {
        padding: 8px 10px;
        border: 1px solid #d9e2ec;
        border-left: 4px solid #90a4ae;
        border-radius: 6px;
        background: #ffffff;
    }

    .custom-query-entry.is-open-awaiting {
        border-left-color: #c00000;
    }

    .custom-query-entry.is-open-responded {
        border-left-color: #1f4f8a;
    }

    .custom-query-entry.is-closed {
        border-left-color: #2f855a;
    }

    .custom-query-entry-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 6px;
        font-weight: 600;
    }

    .custom-query-entry-user {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
    }

    .custom-query-entry-user img {
        width: 16px;
        height: 16px;
        flex-shrink: 0;
    }

    .custom-query-entry-time {
        font-size: 12px;
        color: #52606d;
        white-space: nowrap;
    }

    .custom-query-entry-meta {
        font-size: 12px;
        color: #52606d;
        margin-bottom: 8px;
    }

    .custom-query-entry-comment {
        line-height: 1.45;
        color: #102a43;
    }

    .custom-query-empty {
        padding: 14px;
        border: 1px dashed #b8c4ce;
        border-radius: 6px;
        background: #ffffff;
        color: #52606d;
    }

    .custom-query-help {
        margin-bottom: 10px;
        color: #52606d;
    }

    .custom-query-form-grid {
        display: grid;
        gap: 10px;
    }

    .custom-query-form-grid select,
    .custom-query-form-grid textarea {
        width: 100%;
        box-sizing: border-box;
    }

    .custom-query-actions {
        margin-top: 12px;
        text-align: right;
    }

    .custom-query-loading {
        padding: 18px 10px;
        text-align: center;
        color: #52606d;
    }

    .custom-query-dialog-wrapper .custom-query-dialog-toggle {
        position: absolute;
        top: 7px;
        right: 46px;
        z-index: 2;
        width: 30px;
        height: 28px;
        padding: 0;
        border: 1px solid #c7d2de;
        border-radius: 4px;
        background: #ffffff;
        color: #44556b;
        font-size: 13px;
        line-height: 1;
        cursor: pointer;
    }

    .custom-query-dialog-wrapper .custom-query-dialog-toggle:hover,
    .custom-query-dialog-wrapper .custom-query-dialog-toggle:focus {
        background: #f8fafc;
        outline: none;
    }

</style>
HTML;
    }

    /**
     * Render a small field-specific script so each tagged field gets its own icon and modal.
     */
    private function renderCustomQueryFieldScript($fieldName, $fieldBlockId, array $queryContext)
    {
        $queryContextJsonString = json_encode($queryContext, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $fieldSlug = preg_replace('/[^A-Za-z0-9_-]/', '_', $fieldName);

        echo <<<HTML
<script>
document.addEventListener("DOMContentLoaded", function () {
    var customQueryFieldBlock = document.getElementById("{$fieldBlockId}");
    if (!customQueryFieldBlock) {
        return;
    }

    var queryContext = {$queryContextJsonString};
    var modalId = "custom-query-modal-{$fieldSlug}";
    var queryButton = createQueryButton();
    var isDialogExpanded = false;

    insertQueryButton(queryButton);
    refreshQueryButtonState();

    function buildQueryPayload() {
        return {
            record: queryContext.record,
            eventId: queryContext.eventId,
            fieldName: queryContext.fieldName,
            instance: queryContext.repeatInstance,
            formName: queryContext.instrument
        };
    }

    function postUrlEncoded(payload) {
        return fetch(queryContext.ajaxUrl, {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: new URLSearchParams(payload).toString()
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok || (data.status && data.status === "error")) {
                    throw new Error((data && data.message) ? data.message : "The request failed.");
                }
                return data;
            });
        });
    }

    function createQueryButton() {
        var button = document.createElement("button");
        button.type = "button";
        button.className = "custom-query-button";
        button.innerHTML = buildQcBadgeMarkup("new", "custom-query-button-badge");
        button.title = "No query history yet";
        button.setAttribute("aria-label", "Custom query. No query history yet");
        button.addEventListener("click", function () {
            if (!queryContext.hasSavedRecord) {
                alert("Please save the record before raising a custom query.");
                return;
            }

            openQueryModal();
        });

        return button;
    }

    // Render a compact CQ circle so the field trigger stays the same size as
    // REDCap's native field icons while still showing the custom query state.
    function buildQcBadgeMarkup(iconState, extraClassName) {
        var normalizedState = String(iconState || "new").toLowerCase().replace(/[^a-z-]/g, "");
        var badgeClassName = "custom-query-badge is-" + normalizedState;
        if (extraClassName) {
            badgeClassName += " " + extraClassName;
        }

        return '<span class="' + badgeClassName + '" aria-hidden="true"><span class="custom-query-badge-text">CQ</span></span>';
    }

    function insertQueryButton(button) {
        var slot = document.createElement("span");
        slot.className = "custom-query-button-slot";
        slot.appendChild(button);

        // Match REDCap's native right-hand field icon stack and append the custom
        // query icon last so it sits underneath the existing icons.
        var iconCell = customQueryFieldBlock.querySelector(".rc-field-icons");
        if (iconCell) {
            var lastMeaningfulNode = iconCell.lastChild;
            while (lastMeaningfulNode && lastMeaningfulNode.nodeType === Node.TEXT_NODE && lastMeaningfulNode.textContent.trim() === "") {
                lastMeaningfulNode = lastMeaningfulNode.previousSibling;
            }

            if (lastMeaningfulNode && lastMeaningfulNode.nodeName !== "BR") {
                iconCell.appendChild(document.createElement("br"));
            }

            iconCell.appendChild(slot);
            return;
        }

        var labelCell = customQueryFieldBlock.querySelector("td.labelrc, td.labelrc-nowrap, td.label");
        if (labelCell) {
            labelCell.appendChild(slot);
        }
    }

    function setQueryButtonState(summary) {
        if (!queryButton) {
            return;
        }

        var buttonText = summary && summary.buttonText ? summary.buttonText : "Custom query";
        var statusText = summary && summary.statusText ? summary.statusText : "Custom query";
        var iconState = summary && summary.iconState ? summary.iconState : "new";
        var entryCount = summary && summary.entryCount ? parseInt(summary.entryCount, 10) : 0;
        var historyLabel = (!isNaN(entryCount) && entryCount > 0) ? (" " + entryCount + " history entr" + (entryCount === 1 ? "y." : "ies.")) : "";

        queryButton.innerHTML = buildQcBadgeMarkup(iconState, "custom-query-button-badge");
        queryButton.classList.toggle("has-history", !isNaN(entryCount) && entryCount > 0);
        queryButton.title = buttonText + ": " + statusText + ((entryCount > 0) ? (" (" + entryCount + ")") : "");
        queryButton.setAttribute("aria-label", buttonText + ". " + statusText + historyLabel);
    }

    function refreshQueryButtonState() {
        if (!queryContext.hasSavedRecord) {
            setQueryButtonState({
                buttonText: "Custom query",
                statusText: "Save the record first"
            });
            return;
        }

        var payload = buildQueryPayload();
        payload.queryAction = "getQuerySummary";

        postUrlEncoded(payload)
            .then(function (data) {
                setQueryButtonState(data.summary);
            })
            .catch(function (error) {
                console.error("Unable to refresh custom query state:", error);
            });
    }

    function ensureQueryModal() {
        var modal = document.getElementById(modalId);
        if (!modal) {
            modal = document.createElement("div");
            modal.id = modalId;
            document.body.appendChild(modal);
        }

        return modal;
    }

    function getCollapsedDialogMaxHeight() {
        return Math.min(window.innerHeight - 40, Math.max(760, window.innerHeight - 80));
    }

    function getExpandedDialogWidth() {
        return Math.max(320, window.innerWidth - 32);
    }

    function getExpandedDialogHeight() {
        return Math.max(480, window.innerHeight - 32);
    }

    function updateDialogToggleButton(modal) {
        if (!$(modal).hasClass("ui-dialog-content")) {
            return;
        }

        var toggleButton = $(modal).dialog("widget").find(".custom-query-dialog-toggle");
        if (!toggleButton.length) {
            return;
        }

        toggleButton.html(isDialogExpanded
            ? '<i class="fas fa-window-restore" aria-hidden="true"></i>'
            : '<i class="fas fa-window-maximize" aria-hidden="true"></i>');
        toggleButton.attr("title", isDialogExpanded ? "Restore the regular modal size" : "Expand this modal to near full page");
        toggleButton.attr("aria-label", isDialogExpanded ? "Restore the regular modal size" : "Expand this modal to near full page");
        toggleButton.attr("aria-pressed", isDialogExpanded ? "true" : "false");
    }

    function applyDialogSize(modal) {
        if (!$(modal).hasClass("ui-dialog-content")) {
            return;
        }

        var dialogWidget = $(modal).dialog("widget");
        if (isDialogExpanded) {
            modal.classList.add("custom-query-modal-expanded");
            dialogWidget.addClass("custom-query-dialog-expanded");
            $(modal).dialog("option", "width", getExpandedDialogWidth());
            $(modal).dialog("option", "height", getExpandedDialogHeight());
            $(modal).dialog("option", "maxHeight", getExpandedDialogHeight());
        } else {
            modal.classList.remove("custom-query-modal-expanded");
            dialogWidget.removeClass("custom-query-dialog-expanded");
            $(modal).dialog("option", "width", 760);
            $(modal).dialog("option", "height", "auto");
            $(modal).dialog("option", "maxHeight", getCollapsedDialogMaxHeight());
        }

        $(modal).dialog("option", "position", {
            my: "center",
            at: "center",
            of: window
        });

        updateDialogToggleButton(modal);
    }

    function ensureDialogToggleButton(modal) {
        if (!$(modal).hasClass("ui-dialog-content")) {
            return;
        }

        var titlebar = $(modal).dialog("widget").find(".ui-dialog-titlebar");
        var toggleButton = titlebar.find(".custom-query-dialog-toggle");
        if (!toggleButton.length) {
            toggleButton = $('<button type="button" class="custom-query-dialog-toggle"></button>');
            toggleButton.on("click", function (event) {
                event.preventDefault();
                event.stopPropagation();
                isDialogExpanded = !isDialogExpanded;
                applyDialogSize(modal);
            });
            titlebar.append(toggleButton);
        }

        updateDialogToggleButton(modal);
    }

    function bindQueryModalActions(modal) {
        var saveButton = modal.querySelector("[data-custom-query-save]");
        if (!saveButton) {
            return;
        }

        saveButton.addEventListener("click", function () {
            var statusField = modal.querySelector("#custom-query-status");
            var responseField = modal.querySelector("#custom-query-response");
            var commentField = modal.querySelector("#custom-query-comment");

            var payload = buildQueryPayload();
            payload.queryAction = "saveQueryThread";
            payload.status = statusField ? statusField.value : "OPEN";
            payload.response = responseField ? responseField.value : "";
            payload.comment = commentField ? commentField.value : "";

            saveButton.disabled = true;

            postUrlEncoded(payload)
                .then(function (data) {
                    if (data.summary) {
                        setQueryButtonState(data.summary);
                    }
                    if ($(modal).hasClass("ui-dialog-content")) {
                        $(modal).dialog("close");
                    }
                })
                .catch(function (error) {
                    saveButton.disabled = false;
                    alert(error.message);
                });
        });
    }

    function loadQueryThread(modal) {
        var payload = buildQueryPayload();
        payload.queryAction = "getQueryThread";

        modal.innerHTML = '<div class="custom-query-loading">Loading custom query history...</div>';

        postUrlEncoded(payload)
            .then(function (data) {
                modal.innerHTML = data.html;
                bindQueryModalActions(modal);
                if ($(modal).hasClass("ui-dialog-content")) {
                    $(modal).dialog("option", "position", {
                        my: "center",
                        at: "center",
                        of: window
                    });
                }
                if (data.summary) {
                    setQueryButtonState(data.summary);
                }
            })
            .catch(function (error) {
                modal.innerHTML = '<div class="red" style="padding:12px;">' + error.message + '</div>';
            });
    }

    function openQueryModal() {
        var modal = ensureQueryModal();
        var dialogPosition = {
            my: "center",
            at: "center",
            of: window
        };

        if ($(modal).hasClass("ui-dialog-content")) {
            $(modal).dialog("open");
        } else {
            $(modal).dialog({
                modal: true,
                width: 760,
                resizable: false,
                maxHeight: getCollapsedDialogMaxHeight(),
                position: dialogPosition,
                title: "Custom Query",
                dialogClass: "custom-query-dialog-wrapper",
                close: function () {
                    isDialogExpanded = false;
                    modal.classList.remove("custom-query-modal-expanded");
                    $(this).dialog("widget").removeClass("custom-query-dialog-expanded");
                    updateDialogToggleButton(this);
                }
            });
        }

        ensureDialogToggleButton(modal);
        applyDialogSize(modal);
        loadQueryThread(modal);
    }
});
</script>
HTML;
    }
}
