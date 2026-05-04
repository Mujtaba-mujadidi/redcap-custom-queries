# REDCap Custom Query External Module

This module adds a custom field-level query icon to data entry fields tagged with `@CUSTOMQUERY`.

## How it works

- The icon is shown only to users whose REDCap role is selected in the module setting `Roles allowed to use custom query icons`.
- Clicking the icon opens a custom modal that lets the user:
  - view query history
  - open a query
  - respond to a query
  - close or reopen a query
- Query history is stored in REDCap's native query tables:
  - `redcap_data_quality_status`
  - `redcap_data_quality_resolutions`

## Setup

1. Enable the module for the project.
2. Select the REDCap roles allowed to use the icon in the project settings.
3. Add the action tag `@CUSTOMQUERY` to any field that should show the icon.

## Notes

- Users do not need native REDCap Data Resolution Workflow rights to use this custom modal.
- Users still need access to the underlying instrument/form to use the icon.
