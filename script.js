if ("scrollRestoration" in history) {
  history.scrollRestoration = "manual";
}

(() => {
  const config = window.RVISION_MEMBER || {};
  const menu = document.querySelector("[data-member-menu]");
  const modal = document.querySelector("[data-member-modal]");
  const message = document.querySelector("[data-member-message]");
  const title = document.querySelector("[data-member-title]");
  const catalogLink = document.querySelector(".catalog-cta");
  const state = {
    loggedIn: false,
    member: null,
    pendingDownload: false,
    registerEmail: "",
    resetEmail: "",
  };

  if (!menu || !modal) {
    return;
  }

  const titles = {
    login: "会员登录",
    register: "注册账号",
    "register-code": "邮箱验证",
    "reset-request": "找回密码",
    "reset-code": "重置密码",
  };

  const forms = [...modal.querySelectorAll("[data-member-view]")];

  function setMessage(text = "", type = "") {
    message.textContent = text;
    message.classList.toggle("is-error", type === "error");
    message.classList.toggle("is-success", type === "success");
  }

  function setView(view) {
    forms.forEach((form) => {
      form.hidden = form.dataset.memberView !== view;
    });
    title.textContent = titles[view] || "会员中心";
    setMessage("");
  }

  function openModal(view = "login", options = {}) {
    if (Object.prototype.hasOwnProperty.call(options, "download")) {
      state.pendingDownload = Boolean(options.download);
    }
    setView(view);
    modal.hidden = false;
    document.body.style.overflow = "hidden";
    const firstInput = modal.querySelector(`[data-member-view="${view}"] input`);
    if (firstInput) {
      firstInput.focus();
    }
  }

  function closeModal() {
    modal.hidden = true;
    document.body.style.overflow = "";
    setMessage("");
  }

  function updateMemberUi() {
    menu.dataset.state = state.loggedIn ? "logged-in" : "guest";
    const status = menu.querySelector("[data-member-status]");
    const initial = menu.querySelector("[data-member-initial]");
    if (state.loggedIn && state.member) {
      status.textContent = `${state.member.name} · ${state.member.company}`;
      initial.textContent = state.member.initial || "R";
    } else {
      status.textContent = "未登录";
      initial.textContent = "R";
    }
  }

  async function api(action, data = {}) {
    if (!config.ajaxUrl || !config.nonce) {
      throw new Error("会员功能需要在正式 WordPress 网站中使用。");
    }

    const body = new URLSearchParams({
      action,
      nonce: config.nonce,
      ...data,
    });
    const response = await fetch(config.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body,
    });
    const payload = await response.json();
    if (!payload.success) {
      throw new Error(payload.data?.message || "操作失败，请稍后重试。");
    }
    return payload.data || {};
  }

  async function refreshMember() {
    if (!config.ajaxUrl || !config.nonce) {
      updateMemberUi();
      return;
    }

    try {
      const data = await api("rvision_member_me");
      state.loggedIn = Boolean(data.loggedIn);
      state.member = data.member || null;
      updateMemberUi();
    } catch (error) {
      state.loggedIn = false;
      state.member = null;
      updateMemberUi();
    }
  }

  function downloadCatalog() {
    window.location.href = config.downloadUrl || "/catalog-download/";
  }

  function formData(form) {
    return Object.fromEntries(new FormData(form).entries());
  }

  async function submitForm(form, action, onSuccess, extraData = {}) {
    const submit = form.querySelector("[type='submit']");
    submit.disabled = true;
    setMessage("处理中...");
    try {
      const data = await api(action, { ...formData(form), ...extraData });
      await onSuccess(data, form);
    } catch (error) {
      setMessage(error.message, "error");
    } finally {
      submit.disabled = false;
    }
  }

  menu.querySelector("[data-member-toggle]").addEventListener("click", () => {
    menu.classList.toggle("is-open");
  });

  document.addEventListener("click", (event) => {
    if (!menu.contains(event.target)) {
      menu.classList.remove("is-open");
    }
  });

  document.querySelectorAll("[data-member-open]").forEach((button) => {
    button.addEventListener("click", () => openModal(button.dataset.memberOpen));
  });

  modal.querySelectorAll("[data-member-close]").forEach((button) => {
    button.addEventListener("click", closeModal);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !modal.hidden) {
      closeModal();
    }
  });

  if (catalogLink) {
    catalogLink.addEventListener("click", (event) => {
      if (!state.loggedIn) {
        event.preventDefault();
        openModal("login", { download: true });
      }
    });
  }

  menu.querySelector("[data-member-download]").addEventListener("click", downloadCatalog);

  menu.querySelector("[data-member-logout]").addEventListener("click", async () => {
    try {
      await api("rvision_member_logout");
    } catch (error) {
      // Treat logout as local state cleanup even if the session already expired.
    }
    state.loggedIn = false;
    state.member = null;
    updateMemberUi();
  });

  modal.querySelector('[data-member-view="login"]').addEventListener("submit", (event) => {
    event.preventDefault();
    submitForm(event.currentTarget, "rvision_member_login", async (data) => {
      state.loggedIn = true;
      state.member = data.member;
      updateMemberUi();
      closeModal();
      if (state.pendingDownload) {
        downloadCatalog();
      }
    });
  });

  modal.querySelector('[data-member-view="register"]').addEventListener("submit", (event) => {
    event.preventDefault();
    submitForm(event.currentTarget, "rvision_member_register", async (data, form) => {
      state.registerEmail = data.email || form.elements.email.value;
      setView("register-code");
      setMessage("验证码已发送，请输入邮箱中的 6 位验证码。", "success");
    });
  });

  modal.querySelector('[data-member-view="register-code"]').addEventListener("submit", (event) => {
    event.preventDefault();
    submitForm(
      event.currentTarget,
      "rvision_member_verify_registration",
      async (payload) => {
        state.loggedIn = true;
        state.member = payload.member;
        updateMemberUi();
        closeModal();
        if (state.pendingDownload) {
          downloadCatalog();
        }
      },
      { email: state.registerEmail },
    );
  });

  modal.querySelector('[data-member-view="reset-request"]').addEventListener("submit", (event) => {
    event.preventDefault();
    submitForm(event.currentTarget, "rvision_member_request_password_reset", async (data, form) => {
      state.resetEmail = form.elements.email.value;
      setView("reset-code");
      setMessage(data.message || "验证码已发送，请检查邮箱。", "success");
    });
  });

  modal.querySelector('[data-member-view="reset-code"]').addEventListener("submit", (event) => {
    event.preventDefault();
    submitForm(
      event.currentTarget,
      "rvision_member_reset_password",
      async () => {
        setView("login");
        setMessage("密码已重置，请使用新密码登录。", "success");
      },
      { email: state.resetEmail },
    );
  });

  const params = new URLSearchParams(window.location.search);
  if (params.get("member") === "login") {
    openModal("login", { download: params.get("next") === "catalog-download" });
  } else if (params.get("member") === "register") {
    openModal("register");
  }

  refreshMember();
  window.__rvisionMemberReady = true;
  document.documentElement.dataset.memberReady = "true";
})();
