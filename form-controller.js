import { normalizeFormData } from './ui-utils.js';

export function wireCrudForm({
  apiPost,
  formId,
  messageId,
  mode,
  id,
  createAction,
  updateAction,
  transform = normalizeFormData,
  onSaved,
  deleteButtonId = null,
  deleteAction = null,
  deleteConfirm = 'Delete this item? This cannot be undone.',
  onDeleted = null
}) {
  const form = document.getElementById(formId);
  const message = document.getElementById(messageId);
  const deleteButton = deleteButtonId ? document.getElementById(deleteButtonId) : null;

  if (deleteButton && deleteAction) {
    deleteButton.addEventListener('click', async () => {
      if (!confirm(deleteConfirm)) {
        return;
      }
      message.textContent = 'Deleting…';
      try {
        await apiPost({ action: deleteAction, id }, {});
        message.textContent = 'Deleted.';
        if (onDeleted) {
          onDeleted();
        }
      } catch (error) {
        message.textContent = `Error: ${error.message}`;
      }
    });
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    message.textContent = 'Saving…';
    const data = transform(Object.fromEntries(new FormData(form).entries()));
    const action = mode === 'create' ? createAction : updateAction;
    const params = mode === 'create' ? { action } : { action, id };

    try {
      const result = await apiPost(params, data);
      message.textContent = mode === 'create' ? 'Created.' : 'Saved.';
      if (onSaved) {
        onSaved(result);
      }
    } catch (error) {
      message.textContent = `Error: ${error.message}`;
    }
  });
}
