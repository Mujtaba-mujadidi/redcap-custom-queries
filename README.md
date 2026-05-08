# REDCap Custom Query External Module (Version 1.0.0)

## Overview

The **REDCap Custom Query External Module** is a custom-developed REDCap external module that adds a field-level custom query icon to data entry forms.  
It allows authorised users to raise and manage queries on selected fields without needing native REDCap Data Resolution Workflow access.

The module identifies eligible fields using the `@CUSTOMQUERY` action tag and displays a custom query icon in the standard REDCap field icon area.  
When the icon is clicked, the module opens a custom modal where users can review query history, open new queries, respond to existing queries, close queries, or reopen closed queries.

The module stores query status and history in REDCap's native query tables to preserve compatibility with the standard REDCap data resolution model.

## Features

- **Field-level custom query icon**
  - Adds a custom query icon only to fields tagged with `@CUSTOMQUERY`.

- **Role-based access control**
  - Displays the icon only to users whose REDCap role is selected in the module project setting `custom_query_roles_allowed`.

- **Custom query modal**
  - Allows users to:
    - view query history
    - open a query
    - add an update
    - respond to a query
    - close a query
    - reopen a closed query

- **Native REDCap query storage**
  - Stores query threads in:
    - `redcap_data_quality_status`
    - `redcap_data_quality_resolutions`

- **Repeating form and event support**
  - Keeps queries separate by record, event, field, and repeat instance.

- **Response validation**
  - Requires a comment whenever a response type is selected.
  - If the latest open step is already a response, restricts the next actions to:
    - `Close query`
    - `Send back for further attention`

- **Case-insensitive action tag detection**
  - Accepts `@CUSTOMQUERY`, `@customquery`, and mixed-case variants.

## Setup

1. Enable the module for the project.
2. In the module project settings, select the REDCap roles allowed to use custom query icons.
3. Add the action tag `@CUSTOMQUERY` to any field where the custom query icon should appear.

## Notes

- Users do **not** need native REDCap Data Resolution Workflow rights to use the custom query modal.
- Users must still have normal access to the underlying instrument or form.
- The custom query icon can appear on an unsaved record, but the user must save the record before opening a query.
- The field icon uses a custom `CQ` badge, while the modal uses REDCap's native query icons for status and history display.

## Version History

### Version 1.0.0 (04/05/2026)
- **Initial release**
  - Added role-based field-level custom query icons using the `@CUSTOMQUERY` action tag
  - Added custom modal for viewing history and managing query threads
  - Stored query status and history in REDCap native query tables
  - Added support for repeating forms and repeat instances
  - Added response validation and restricted follow-up actions after a coded response
