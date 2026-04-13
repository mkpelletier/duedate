/**
 * Injects due date and penalty information into the activity-dates region.
 *
 * @module     quizaccess_duedate/duedate_display
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Initialise the due date display injection.
 *
 * @param {string} dueDateLabel The label for the due date (e.g. "Due:")
 * @param {string} dueDateString The formatted due date string
 * @param {string|null} penaltyText The penalty information text, or null if no penalty
 */
export const init = (dueDateLabel, dueDateString, penaltyText) => {
    // Find the activity-dates container.
    let datesContainer = document.querySelector('[data-region="activity-dates"]');

    // If the container doesn't exist, create it inside activity-information.
    if (!datesContainer) {
        const activityInfo = document.querySelector('[data-region="activity-information"]');
        if (!activityInfo) {
            return;
        }
        datesContainer = document.createElement('div');
        datesContainer.setAttribute('data-region', 'activity-dates');
        datesContainer.classList.add('activity-dates');
        activityInfo.appendChild(datesContainer);
    }

    // Create the due date entry matching core_course/activity_date template structure.
    const dueDateDiv = document.createElement('div');
    const strong = document.createElement('strong');
    strong.textContent = dueDateLabel;
    dueDateDiv.appendChild(strong);
    dueDateDiv.appendChild(document.createTextNode(' ' + dueDateString));
    datesContainer.appendChild(dueDateDiv);

    // Add penalty info if present.
    if (penaltyText) {
        const penaltyDiv = document.createElement('div');
        penaltyDiv.classList.add('small', 'text-muted', 'mt-1');
        penaltyDiv.textContent = penaltyText;
        datesContainer.appendChild(penaltyDiv);
    }
};
