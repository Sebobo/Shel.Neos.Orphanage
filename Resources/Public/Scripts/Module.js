document.addEventListener('DOMContentLoaded', () => {
    /**
     * @typedef {Object} Notification
     * @property {Array<{severity: string, title: string, message: string}>} value
     */

    /**
     * @typedef {Object} NotificationEvent
     * @property {Notification} detail
     */

    /**
     * Adds an event listener for 'notify' events on the document body.
     */
    document.body.addEventListener('app:notify', /** @param {NotificationEvent} evt */ function(evt) {
        evt.detail.value.forEach(({ severity, title, message }) => {
            if (title) {
                NeosCMS.Notification[severity.toLowerCase()](title, message);
            } else {
                NeosCMS.Notification[severity.toLowerCase()](message);
            }
        });
    });
});
