let taskPollInterval;

// --- NEW: Helper to find the daterange ---
function getTaskDateRange(element) {
  // Find the main wrapper
  const wrapper = element.closest('.task-runner-wrapper');
  if (wrapper && wrapper.dataset.daterange) {
    return wrapper.dataset.daterange;
  }
  // Fallback for the run button
  const hiddenInput = document.getElementById('task_daterange');
  if (hiddenInput) {
    return hiddenInput.value;
  }
  return null;
}

document.addEventListener('DOMContentLoaded', function () {
  
  // Find all our elements
  // We need to use querySelector because IDs might not be unique if shortcode is on page twice
  const wrapper = document.querySelector('.task-runner-wrapper');
  if (!wrapper) return; // No task runner on this page

  const runBtn = wrapper.querySelector('#run-task-btn');
  const showBtn = wrapper.querySelector('#show-results-btn');
  const resetBtn = wrapper.querySelector('#reset-task-btn');
  const runningMsg = wrapper.querySelector('#task-running-message');
  const statusMsg = wrapper.querySelector('#task-status-message');
  const daterange = wrapper.dataset.daterange;

  // --- MODIFIED: Check if task is running on page load ---
  if (runningMsg && runningMsg.style.display === 'block') {
    statusMsg.textContent = 'Task is already running. Checking for completion...';
    startPolling(daterange);
  }
  
  // 1. Handler for the "Run Task" button
  if (runBtn) {
    runBtn.addEventListener('click', function (e) {
      e.preventDefault();
      const daterange = getTaskDateRange(e.target);
      if (!daterange) {
          statusMsg.textContent = 'Error: Could not find daterange.';
          return;
      }

      runBtn.disabled = true;
      runBtn.textContent = 'Scheduling...';
      statusMsg.textContent = 'Contacting server to schedule task...';

      const formData = new FormData();
      formData.append('action', 'run_my_task_schedule');
      formData.append('security', my_task_ajax.nonce);
      formData.append('daterange', daterange); // --- CHANGED ---

      fetch(my_task_ajax.ajax_url, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(response => {
          if (response.success) {
            statusMsg.textContent = response.data.message;
            runBtn.style.display = 'none';
            wrapper.querySelector('#my-task-form').style.display = 'none';
            runningMsg.style.display = 'block';

            startPolling(daterange); // --- CHANGED ---
          } else {
            statusMsg.textContent = 'Error: ' + response.data.message;
            runBtn.disabled = false;
            runBtn.textContent = 'Generate Report';
          }
        })
        .catch(error => { /* (unchanged) */ });
    });
  }

  // 2. Handler for the "Show Results" button
  // --- IMPORTANT ---
  // We must use event delegation because this button might not exist on page load
  document.body.addEventListener('click', function(e) {
    if (e.target.id === 'show-results-btn') {
      e.preventDefault();
      const wrapper = e.target.closest('.task-runner-wrapper');
      const resultsContainer = wrapper.querySelector('#task-results-container');
      
      const isVisible = resultsContainer.style.display === 'block';
      if (isVisible) {
        resultsContainer.style.display = 'none';
        e.target.textContent = 'Show Task Results';
      } else {
        resultsContainer.style.display = 'block';
        e.target.textContent = 'Hide Task Results';
      }
    }
  });

  // 3. Handler for the "Reset" button
  // --- IMPORTANT ---
  // Must also use event delegation
  document.body.addEventListener('click', function(e) {
    if (e.target.id === 'reset-task-btn') {
      e.preventDefault();
      if (!confirm('Are you sure you want to reset this task?')) return;
      
      const daterange = getTaskDateRange(e.target);
      if (!daterange) {
          alert('Error: Could not find daterange to reset.');
          return;
      }
      
      if (taskPollInterval) clearInterval(taskPollInterval);

      e.target.disabled = true;
      const statusMsg = e.target.closest('.task-runner-wrapper').querySelector('#task-status-message');
      statusMsg.textContent = 'Resetting task...';
      
      const formData = new FormData();
      formData.append('action', 'reset_my_task');
      formData.append('security', my_task_ajax.nonce);
      formData.append('daterange', daterange); // --- CHANGED ---

      fetch(my_task_ajax.ajax_url, { method: 'POST', body: formData })
      .then(response => response.json())
      .then(response => {
        if (response.success) {
          statusMsg.textContent = response.data.message;
          location.reload(); 
        } else {
          statusMsg.textContent = 'Error: Could not reset task.';
          e.target.disabled = false;
        }
      });
    }
  });


  // --- MODIFIED: Polling Functions ---

  function startPolling(daterange) {
    if (!daterange) return;
    if (taskPollInterval) clearInterval(taskPollInterval);
    
    // Poll immediately, then set interval
    checkTaskStatus(daterange); 
    taskPollInterval = setInterval(() => checkTaskStatus(daterange), 5000); 
  }

  function checkTaskStatus(daterange) {
    const formData = new FormData();
    formData.append('action', 'check_my_task_status');
    formData.append('security', my_task_ajax.nonce);
    formData.append('daterange', daterange); // --- CHANGED ---

    fetch(my_task_ajax.ajax_url, { method: 'POST', body: formData })
    .then(response => response.json())
    .then(response => {
      const wrapper = document.querySelector(`.task-runner-wrapper[data-daterange="${daterange}"]`);
      if (!wrapper) {
          clearInterval(taskPollInterval);
          return;
      }
      
      const statusMsg = wrapper.querySelector('#task-status-message');

      if (response.success) {
        if (response.data.status === 'complete') {
          // --- SUCCESS: The task is done! ---
          console.log('Task is complete!');
          clearInterval(taskPollInterval);

          // --- NEW: Inject the cached HTML ---
          wrapper.innerHTML = response.data.html;

        } else if (response.data.status === 'running') {
          console.log('Task is still running...');
          statusMsg.textContent = 'Task is running in the background...';
        } else {
          console.log('Task status: ' + response.data.status);
        }
      } else {
        console.error('Polling check failed:', response.data.message);
        clearInterval(taskPollInterval);
        statusMsg.textContent = 'Error checking task status.';
      }
    })
    .catch(error => {
      console.error('Polling network error:', error);
      clearInterval(taskPollInterval);
      // (find statusMsg and update it)
    });
  }
});
