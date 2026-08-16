import { normalizeFormData } from './ui-utils.js';

function setFormStatus(message, state, text) {
  if (!message) return;
  message.dataset.state = state;
  message.setAttribute('role', state === 'error' ? 'alert' : 'status');
  message.textContent = text;
}

function setFormBusy(form, busy) {
  form.setAttribute('aria-busy', busy ? 'true' : 'false');
  form.querySelectorAll('button').forEach((button) => {
    button.disabled = busy;
  });
}

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
      setFormBusy(form, true);
      setFormStatus(message, 'busy', 'Deleting…');
      try {
        await apiPost({ action: deleteAction, id }, {});
        setFormStatus(message, 'success', 'Deleted.');
        if (onDeleted) {
          onDeleted();
        }
      } catch (error) {
        setFormStatus(message, 'error', `Error: ${error.message}`);
      } finally {
        setFormBusy(form, false);
      }
    });
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    form.classList.add('was-validated');
    setFormBusy(form, true);
    setFormStatus(message, 'busy', 'Saving…');

    try {
      const data = transform(Object.fromEntries(new FormData(form).entries()));
      const action = mode === 'create' ? createAction : updateAction;
      const params = mode === 'create' ? { action } : { action, id };
      const result = await apiPost(params, data);
      setFormStatus(message, 'success', mode === 'create' ? 'Created.' : 'Saved.');
      if (onSaved) {
        onSaved(result);
      }
    } catch (error) {
      setFormStatus(message, 'error', `Error: ${error.message}`);
    } finally {
      setFormBusy(form, false);
    }
  });
}
