<?php

header('Content-Type: application/json');

if (!isset($_POST['queryAction'])) {
    jsonError('Invalid request.', 400);
}

handleCustomQueryAction($module ?? null, $_POST['queryAction']);

/**
 * Route the custom query requests into the same REDCap data resolution tables
 * used by REDCap's native field-level workflow.
 */
function handleCustomQueryAction($module, $action)
{
    if (!$module || !method_exists($module, 'currentUserCanUseCustomQueries') || !$module->currentUserCanUseCustomQueries()) {
        jsonError('You are not allowed to use custom queries in this project.', 403);
    }

    $context = buildCustomQueryContextFromRequest();
    $history = getCustomFieldQueryHistory($context);
    $summary = buildCustomQuerySummary($history);

    if ($action === 'getQuerySummary') {
        jsonSuccess(['summary' => $summary]);
    }

    if ($action === 'getQueryThread') {
        jsonSuccess([
            'summary' => $summary,
            'html' => renderCustomQueryThreadHtml($context, $history, $summary)
        ]);
    }

    if ($action === 'saveQueryThread') {
        saveCustomQueryThread($module, $context, $history, $summary);
        $history = getCustomFieldQueryHistory($context);
        $summary = buildCustomQuerySummary($history);

        jsonSuccess([
            'message' => 'Custom query updated.',
            'summary' => $summary,
            'html' => renderCustomQueryThreadHtml($context, $history, $summary)
        ]);
    }

    jsonError('Invalid query action.', 400);
}

/**
 * Validate the field-level context so the modal can only be used on fields tagged
 * with @CUSTOMQUERY that the current user can already access on the form.
 */
function buildCustomQueryContextFromRequest()
{
    global $Proj;

    $record = html_entity_decode(urldecode(trim((string) ($_POST['record'] ?? ''))), ENT_QUOTES);
    $fieldName = trim((string) ($_POST['fieldName'] ?? ''));
    $eventId = isset($_POST['eventId']) && is_numeric($_POST['eventId']) ? (int) $_POST['eventId'] : 0;
    $instance = isset($_POST['instance']) && is_numeric($_POST['instance']) ? (int) $_POST['instance'] : 1;
    $instance = ($instance > 0) ? $instance : 1;

    if ($record === '' || $fieldName === '' || $eventId < 1) {
        jsonError('Missing record, field, or event context for the custom query.', 400);
    }

    if (!isset($Proj->metadata[$fieldName])) {
        jsonError('The requested field could not be found in this project.', 400);
    }

    // REDCap stores runtime action tags in the "misc" metadata column for standard
    // project fields. Fall back to field_annotation for contexts that expose that key.
    $fieldAnnotation = $Proj->metadata[$fieldName]['misc'] ?? ($Proj->metadata[$fieldName]['field_annotation'] ?? '');
    if (stripos($fieldAnnotation, '@CUSTOMQUERY') === false) {
        jsonError('This field is not configured with the @CUSTOMQUERY action tag.', 400);
    }

    $fieldForm = $Proj->metadata[$fieldName]['form_name'] ?? '';
    $userRights = \REDCap::getUserRights();
    $currentUserRights = (defined('USERID') && isset($userRights[USERID])) ? $userRights[USERID] : [];
    $formRights = (string) ($currentUserRights['forms'][$fieldForm] ?? '0');
    if ($formRights === '0') {
        jsonError('You do not have access to this form.', 403);
    }

    $repeatInstrument = $Proj->isRepeatingForm($eventId, $fieldForm) ? $fieldForm : null;

    return [
        'record' => $record,
        'fieldName' => $fieldName,
        'fieldLabel' => strip_tags($Proj->metadata[$fieldName]['element_label'] ?? $fieldName),
        'eventId' => $eventId,
        'instance' => $instance,
        'fieldForm' => $fieldForm,
        'repeatInstrument' => $repeatInstrument,
    ];
}

/**
 * Use REDCap's DataQuality helper so the modal reflects the same thread history
 * stored for the field in the native query tables.
 */
function getCustomFieldQueryHistory(array $context)
{
    $dataQuality = new \DataQuality();

    return $dataQuality->getFieldDataResHistory(
        $context['record'],
        $context['eventId'],
        $context['fieldName'],
        '',
        $context['instance']
    );
}

/**
 * Reduce the history rows down into the small summary needed by the field icon and modal.
 */
function buildCustomQuerySummary(array $history)
{
    $entryCount = count($history);
    if ($entryCount === 0) {
        return [
            'currentStatus' => 'NEW',
            'buttonText' => 'Custom query',
            'statusText' => 'No query history yet',
            'iconState' => 'new',
            'iconUrl' => APP_PATH_IMAGES . 'balloon_left_bw2.gif',
            'entryCount' => 0,
            'latestResponse' => ''
        ];
    }

    $latestEntry = $history[$entryCount - 1];
    $currentStatus = $latestEntry['query_status'] ?? ($latestEntry['current_query_status'] ?? 'OPEN');
    $latestResponse = $latestEntry['response'] ?? '';
    $iconState = buildCustomQueryStateKey($currentStatus, $latestResponse);

    if ($currentStatus === 'OPEN' && $latestResponse === '') {
        $statusText = 'Open - awaiting response';
    } elseif ($currentStatus === 'OPEN' && $latestResponse !== '') {
        $statusText = 'Open - response added';
    } elseif ($currentStatus === 'CLOSED') {
        $statusText = 'Closed';
    } elseif ($currentStatus === 'VERIFIED') {
        $statusText = 'Verified';
    } elseif ($currentStatus === 'DEVERIFIED') {
        $statusText = 'De-verified';
    } else {
        $statusText = 'Query history available';
    }

    return [
        'currentStatus' => $currentStatus,
        'buttonText' => 'Custom query',
        'statusText' => $statusText,
        'iconState' => $iconState,
        'iconUrl' => buildHistoryEntryIcon($currentStatus, $latestResponse),
        'entryCount' => $entryCount,
        'latestResponse' => $latestResponse
    ];
}

/**
 * Render the modal view for the custom query thread.
 */
function renderCustomQueryThreadHtml(array $context, array $history, array $summary)
{
    global $Proj;

    $eventName = $Proj->eventInfo[$context['eventId']]['name_ext'] ?? '';
    $responseChoices = getCustomQueryResponseChoiceLabels();
    $historyCardClass = 'custom-query-summary-card custom-query-history-card';
    if (count($history) > 1) {
        // Only stretch the middle history section when there are multiple cards to show.
        $historyCardClass .= ' has-multiple-history';
    }

    $html = '<div class="custom-query-shell">';
    $html .= '<div class="custom-query-summary-card custom-query-header-card">';
    $html .= '<div class="custom-query-summary-grid">';
    $html .= '<div><div class="custom-query-label">Record</div><div class="custom-query-summary-value">' . escapeHtml($context['record']) . '</div></div>';
    $html .= '<div><div class="custom-query-label">Field</div><div class="custom-query-summary-value"><strong>' . escapeHtml($context['fieldName']) . '</strong><span class="custom-query-summary-secondary">' . escapeHtml($context['fieldLabel']) . '</span></div></div>';
    if ($eventName !== '') {
        $html .= '<div><div class="custom-query-label">Event</div><div class="custom-query-summary-value">' . escapeHtml($eventName) . '</div></div>';
    }
    $html .= '<div><div class="custom-query-label">Status</div><div class="custom-query-status-pill"><img src="' . escapeHtml($summary['iconUrl'] ?? (APP_PATH_IMAGES . 'balloon_left_bw2.gif')) . '" alt="">' . escapeHtml($summary['statusText']) . '</div></div>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<div class="' . escapeHtml($historyCardClass) . '">';
    $html .= '<div class="custom-query-label">History</div>';
    if (empty($history)) {
        $html .= '<div class="custom-query-empty">No custom query history exists for this field yet.</div>';
    } else {
        $html .= '<div class="custom-query-history-list">';
        $html .= renderCustomQueryHistoryEntries($history);
        $html .= '</div>';
    }
    $html .= '</div>';

    $actionOptions = ['OPEN' => 'Open query'];
    $saveButtonText = 'Open query';
    $helpText = 'Use this note to open a field-level custom query.';
    $showResponseSelect = false;
    $lastStepWasResponse = (!empty($history) && $summary['currentStatus'] === 'OPEN' && ($summary['latestResponse'] ?? '') !== '');

    if ($lastStepWasResponse) {
        // Once the latest step is a coded response, the reviewer should only be
        // able to close the thread or send it back for further attention.
        $actionOptions = [
            'CLOSED' => 'Close query',
            'OPEN' => 'Send back for further attention'
        ];
        $saveButtonText = 'Save update';
        $helpText = 'Review the latest response and either close the query or send it back for further attention.';
    } elseif (!empty($history) && $summary['currentStatus'] === 'OPEN') {
        $actionOptions = [
            'OPEN' => 'Add update and keep open',
            'CLOSED' => 'Close query'
        ];
        $saveButtonText = 'Save update';
        $helpText = 'Add a comment, choose a response type, or close the thread when the issue is resolved.';
        $showResponseSelect = true;
    } elseif (!empty($history) && $summary['currentStatus'] !== 'OPEN') {
        $actionOptions = ['OPEN' => 'Reopen query'];
        $saveButtonText = 'Reopen query';
        $helpText = 'This thread is currently closed. Saving here will reopen it.';
    }

    $html .= '<div class="custom-query-form-card">';
    $html .= '<div class="custom-query-label">Next action</div>';
    $html .= '<div class="custom-query-help">' . escapeHtml($helpText) . '</div>';
    $html .= '<div class="custom-query-form-grid">';
    $html .= '<div><div class="custom-query-label">Action</div><select id="custom-query-status">';
    foreach ($actionOptions as $value => $label) {
        $html .= '<option value="' . escapeHtml($value) . '">' . escapeHtml($label) . '</option>';
    }
    $html .= '</select></div>';

    if ($showResponseSelect) {
        $html .= '<div><div class="custom-query-label">Response type</div><select id="custom-query-response">';
        $html .= '<option value="">None selected</option>';
        foreach ($responseChoices as $value => $label) {
            $html .= '<option value="' . escapeHtml($value) . '">' . escapeHtml($label) . '</option>';
        }
        $html .= '</select></div>';
    }

    $html .= '<div><div class="custom-query-label">Comment</div><textarea id="custom-query-comment" rows="5" placeholder="Add the detail you want the next reviewer to see."></textarea></div>';
    $html .= '</div>';
    $html .= '<div class="custom-query-actions"><button type="button" class="btn btn-primaryrc" data-custom-query-save>' . escapeHtml($saveButtonText) . '</button></div>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

function renderCustomQueryHistoryEntries(array $history)
{
    $usernamesByUiId = getUsernamesByUiId($history);
    $responseChoices = getCustomQueryResponseChoiceLabels();
    $html = '';

    foreach ($history as $entry) {
        $entryStatus = $entry['current_query_status'] ?? ($entry['query_status'] ?? 'OPEN');
        $entryResponse = $entry['response'] ?? '';
        $entryCommentRaw = trim((string) ($entry['comment'] ?? ''));
        $entryCommentDisplay = ($entryCommentRaw === '') ? '<em>No comment added.</em>' : nl2br(escapeHtml($entryCommentRaw));
        $entryUser = $usernamesByUiId[$entry['user_id']] ?? 'System';
        $entryTimestamp = formatCustomQueryTimestamp($entry['ts'] ?? '');
        $entryStatusText = buildHistoryEntryStatusText($entryStatus, $entryResponse, $responseChoices);
        $entryCssClass = buildHistoryEntryCssClass($entryStatus, $entryResponse);
        $entryIcon = buildHistoryEntryIcon($entryStatus, $entryResponse);

        $html .= '<div class="custom-query-entry ' . escapeHtml($entryCssClass) . '">';
        $html .= '<div class="custom-query-entry-head">';
        $html .= '<span class="custom-query-entry-user"><img src="' . escapeHtml($entryIcon) . '" alt="">' . escapeHtml($entryUser) . '</span>';
        $html .= '<span class="custom-query-entry-time">' . escapeHtml($entryTimestamp) . '</span>';
        $html .= '</div>';
        $html .= '<div class="custom-query-entry-meta">' . escapeHtml($entryStatusText) . '</div>';
        $html .= '<div class="custom-query-entry-comment">' . $entryCommentDisplay . '</div>';
        $html .= '</div>';
    }

    return $html;
}

/**
 * Persist the field-level thread in REDCap's native data resolution tables:
 * one status row per field context, plus one history row per update.
 */
function saveCustomQueryThread($module, array $context, array $history, array $summary)
{
    $currentUserUiId = $module->getCurrentUserUiId();
    if (!$currentUserUiId) {
        jsonError('Unable to resolve the current REDCap user for this query update.', 500);
    }

    $validResponseChoices = getCustomQueryResponseChoiceLabels();
    $status = strtoupper(trim((string) ($_POST['status'] ?? 'OPEN')));
    $response = strtoupper(trim((string) ($_POST['response'] ?? '')));
    $comment = decodePostedComment($_POST['comment'] ?? '');

    if (!in_array($status, ['OPEN', 'CLOSED'], true)) {
        jsonError('Invalid query status submitted.', 400);
    }

    if ($response === '') {
        $response = null;
    } elseif (!array_key_exists($response, $validResponseChoices)) {
        jsonError('Invalid response type submitted.', 400);
    }

    if ($comment === '' && $response === null) {
        jsonError('Please add a comment or choose a response type before saving.', 400);
    }

    // Keep the thread history readable by requiring explanatory text whenever a
    // coded response type is selected.
    if ($response !== null && $comment === '') {
        jsonError('Please add a comment when selecting a response type.', 400);
    }

    // Once the latest step is already a response, the next reviewer should only
    // decide whether to close the query or send it back for further attention.
    if (!empty($history) && $summary['currentStatus'] === 'OPEN' && ($summary['latestResponse'] ?? '') !== '' && $response !== null) {
        jsonError('Response type cannot be selected after the latest step was already a response.', 400);
    }

    if (empty($history) && $status !== 'OPEN') {
        jsonError('A custom query must be opened before it can be closed.', 400);
    }

    if (!empty($history) && $summary['currentStatus'] !== 'OPEN' && $status === 'CLOSED') {
        jsonError('Only open custom queries can be closed.', 400);
    }

    $responseRequested = ($status === 'OPEN' && $response === null) ? 1 : 0;

    db_query("START TRANSACTION");

    $statusSql = "insert into redcap_data_quality_status
        (non_rule, project_id, record, event_id, field_name, repeat_instrument, query_status, assigned_user_id, instance)
        values (?, ?, ?, ?, ?, ?, ?, ?, ?)
        on duplicate key update query_status = ?, status_id = LAST_INSERT_ID(status_id)";
    $statusParams = [
        1,
        PROJECT_ID,
        $context['record'],
        $context['eventId'],
        $context['fieldName'],
        $context['repeatInstrument'],
        $status,
        null,
        $context['instance'],
        $status
    ];

    if (!db_query($statusSql, $statusParams)) {
        db_query("ROLLBACK");
        jsonError('Unable to update the custom query status: ' . db_error(), 500);
    }

    $statusId = db_insert_id();
    $timestamp = date('Y-m-d H:i:s');

    $resolutionSql = "insert into redcap_data_quality_resolutions
        (status_id, ts, user_id, response_requested, response, comment, current_query_status)
        values (?, ?, ?, ?, ?, ?, ?)";
    $resolutionParams = [
        $statusId,
        $timestamp,
        $currentUserUiId,
        $responseRequested,
        $response,
        ($comment === '' ? null : $comment),
        $status
    ];

    if (!db_query($resolutionSql, $resolutionParams)) {
        db_query("ROLLBACK");
        jsonError('Unable to save the custom query update: ' . db_error(), 500);
    }

    db_query("COMMIT");

    $logDataValues = json_encode([
        'record' => $context['record'],
        'event_id' => $context['eventId'],
        'field' => $context['fieldName'],
        'instance' => $context['instance'],
        'status' => $status,
        'response' => $response,
        'comment' => $comment,
    ]);

    $_GET['event_id'] = $context['eventId'];
    \Logging::logEvent(
        $statusSql . "\n" . $resolutionSql,
        "redcap_data_quality_resolutions",
        "MANAGE",
        $context['record'],
        $logDataValues,
        buildCustomQueryLogMessage($summary['currentStatus'], $status, $response)
    );
}

function buildCustomQueryLogMessage($previousStatus, $newStatus, $response)
{
    if ($previousStatus === 'NEW' && $newStatus === 'OPEN') {
        return 'Open custom query';
    }

    if ($previousStatus !== 'OPEN' && $newStatus === 'OPEN') {
        return 'Reopen custom query';
    }

    if ($previousStatus === 'OPEN' && $newStatus === 'CLOSED') {
        return 'Close custom query';
    }

    if ($response !== null) {
        return 'Respond to custom query';
    }

    return 'Update custom query';
}

function buildHistoryEntryStatusText($status, $response, array $responseChoices)
{
    if ($status === 'OPEN' && $response === '') {
        return 'Open - awaiting response';
    }

    if ($status === 'OPEN' && $response !== '') {
        $responseLabel = $responseChoices[$response] ?? $response;
        return 'Open - response added (' . $responseLabel . ')';
    }

    if ($status === 'CLOSED') {
        return 'Closed';
    }

    if ($status === 'VERIFIED') {
        return 'Verified';
    }

    if ($status === 'DEVERIFIED') {
        return 'De-verified';
    }

    return 'History update';
}

function buildHistoryEntryCssClass($status, $response)
{
    if ($status === 'OPEN' && $response === '') {
        return 'is-open-awaiting';
    }

    if ($status === 'OPEN' && $response !== '') {
        return 'is-open-responded';
    }

    if ($status === 'CLOSED') {
        return 'is-closed';
    }

    return '';
}

/**
 * Use the same small set of visual states for the field icon, summary pill,
 * and history entries so the color meaning stays consistent everywhere.
 */
function buildCustomQueryStateKey($status, $response)
{
    if ($status === 'OPEN' && $response === '') {
        return 'open-awaiting';
    }

    if ($status === 'OPEN' && $response !== '') {
        return 'open-responded';
    }

    if ($status === 'CLOSED') {
        return 'closed';
    }

    if ($status === 'VERIFIED') {
        return 'verified';
    }

    if ($status === 'DEVERIFIED') {
        return 'deverified';
    }

    return 'default';
}

/**
 * Keep the modal aligned with REDCap's native query visuals by using the same
 * image set for the status pill and history rows.
 */
function buildHistoryEntryIcon($status, $response)
{
    if ($status === 'OPEN' && $response === '') {
        return APP_PATH_IMAGES . 'balloon_exclamation.gif';
    }

    if ($status === 'OPEN' && $response !== '') {
        return APP_PATH_IMAGES . 'balloon_exclamation_blue.gif';
    }

    if ($status === 'CLOSED') {
        return APP_PATH_IMAGES . 'balloon_tick.gif';
    }

    if ($status === 'VERIFIED') {
        return APP_PATH_IMAGES . 'tick_circle.png';
    }

    if ($status === 'DEVERIFIED') {
        return APP_PATH_IMAGES . 'exclamation_red.png';
    }

    return APP_PATH_IMAGES . 'balloon_left.png';
}

function getUsernamesByUiId(array $history)
{
    $usernames = [];
    $uiIds = [];

    foreach ($history as $entry) {
        if (!empty($entry['user_id'])) {
            $uiIds[] = (int) $entry['user_id'];
        }
    }

    $uiIds = array_values(array_unique(array_filter($uiIds)));
    if (empty($uiIds)) {
        return $usernames;
    }

    $sql = "select ui_id, username from redcap_user_information where ui_id in (" . prep_implode($uiIds) . ")";
    $result = db_query($sql);
    while ($result && ($row = db_fetch_assoc($result))) {
        $usernames[$row['ui_id']] = $row['username'];
    }

    return $usernames;
}

function getCustomQueryResponseChoiceLabels()
{
    global $lang;

    return [
        'DATA_MISSING' => $lang['dataqueries_161'] ?? 'Data missing',
        'TYPOGRAPHICAL_ERROR' => $lang['dataqueries_162'] ?? 'Typographical error',
        'WRONG_SOURCE' => $lang['dataqueries_163'] ?? 'Wrong source',
        'CONFIRMED_CORRECT' => $lang['dataqueries_164'] ?? 'Confirmed correct',
        'OTHER' => $lang['create_project_19'] ?? 'Other'
    ];
}

function decodePostedComment($comment)
{
    $comment = (string) $comment;
    if (function_exists('label_decode')) {
        $comment = label_decode($comment);
    }

    return trim($comment);
}

function formatCustomQueryTimestamp($timestamp)
{
    if ($timestamp === '' || $timestamp === null) {
        return '';
    }

    $unixTimestamp = strtotime($timestamp);
    if ($unixTimestamp === false) {
        return (string) $timestamp;
    }

    return date('Y-m-d H:i', $unixTimestamp);
}

function escapeHtml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

function jsonSuccess(array $payload, $httpStatusCode = 200)
{
    http_response_code($httpStatusCode);
    $payload['status'] = 'success';
    echo json_encode($payload);
    exit;
}

function jsonError($message, $httpStatusCode = 400)
{
    http_response_code($httpStatusCode);
    echo json_encode([
        'status' => 'error',
        'message' => $message
    ]);
    exit;
}
