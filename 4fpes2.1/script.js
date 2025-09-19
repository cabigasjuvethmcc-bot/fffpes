document.addEventListener("DOMContentLoaded", () => {
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
