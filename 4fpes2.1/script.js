document.addEventListener("DOMContentLoaded", () => {
  // Dynamically switch username label based on selected role
  function updateLoginIdentityLabel() {
    const roleSel = document.getElementById("role");
    const userInput = document.getElementById("username");
    const userLabel = document.querySelector("label[for='username']");
    if (!roleSel || !userInput || !userLabel) return;
    if (roleSel.value === 'student') {
      userLabel.textContent = 'Student ID:';
      userInput.placeholder = 'Enter your Student ID';
    } else if (roleSel.value === 'faculty' || roleSel.value === 'dean') {
      userLabel.textContent = 'Employee ID:';
      userInput.placeholder = 'Enter your Employee ID (e.g., F-001 / D-001)';
    } else {
      userLabel.textContent = 'Username:';
      userInput.placeholder = 'Enter your username';
    }
  }

  const roleSel = document.getElementById("role");
  if (roleSel) {
    roleSel.addEventListener('change', updateLoginIdentityLabel);
    // Initialize on load
    updateLoginIdentityLabel();
  }

  document.getElementById("login-form").addEventListener("submit", function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'login');
    
    const errorDiv = document.getElementById("login-error");
    const loginBtn = document.getElementById("login-btn");
    
    // Disable button and show loading
    loginBtn.disabled = true;
    loginBtn.textContent = "Logging in...";
    errorDiv.style.display = "none";

    fetch('auth.php', {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        window.location.href = data.redirect;
      } else {
        errorDiv.textContent = data.message;
        errorDiv.style.display = "block";
      }
    })
    .catch(error => {
      errorDiv.textContent = "An error occurred. Please try again.";
      errorDiv.style.display = "block";
    })
    .finally(() => {
      loginBtn.disabled = false;
      loginBtn.textContent = "Login";
    });
  });
});
