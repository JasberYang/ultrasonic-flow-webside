if ("scrollRestoration" in history) {
  history.scrollRestoration = "manual";
}

(function () {
  var config = window.RVISION_MEMBER || {};
  var menu = document.querySelector("[data-member-menu]");
  var modal = document.querySelector("[data-member-modal]");
  var state = {
    loggedIn: false,
    member: null,
    registerEmail: "",
    resetEmail: ""
  };

  if (!menu || !modal) {
    return;
  }

  var dialog = modal.querySelector("[data-active-view]");
  var message = document.querySelector("[data-member-message]");
  var title = document.querySelector("[data-member-title]");
  var description = document.querySelector("[data-member-description]");
  var eyebrow = document.querySelector("[data-member-eyebrow]");
  var forms = modal.querySelectorAll("[data-member-view]");

  var titles = {
    "catalog-email": "接收产品目录",
    login: "会员登录",
    register: "注册会员账号",
    "register-code": "邮箱验证",
    "reset-request": "找回密码",
    "reset-code": "重置密码"
  };

  var descriptions = {
    "catalog-email": "输入邮箱，我们会把 YK-C 产品目录 PDF 发送到该邮箱。",
    login: "输入邮箱和密码登录会员账号。",
    register: "请填写注册信息，带 * 的字段为必填项。提交后会发送邮箱验证码。",
    "register-code": "请输入邮箱收到的 6 位验证码，验证后自动登录。",
    "reset-request": "输入注册邮箱，我们会发送用于找回密码的验证码。",
    "reset-code": "输入邮箱验证码和新密码完成重置。"
  };

  function addEvent(element, eventName, handler) {
    if (!element) {
      return;
    }
    if (element.addEventListener) {
      element.addEventListener(eventName, handler, false);
      return;
    }
    if (element.attachEvent) {
      element.attachEvent("on" + eventName, function () {
        handler.call(element, window.event);
      });
    }
  }

  function preventDefault(event) {
    if (!event) {
      return;
    }
    if (event.preventDefault) {
      event.preventDefault();
    } else {
      event.returnValue = false;
    }
  }

  function getEventTarget(event) {
    return event ? event.target || event.srcElement : null;
  }

  function hasClass(element, className) {
    return (" " + (element.className || "") + " ").indexOf(" " + className + " ") > -1;
  }

  function addClass(element, className) {
    if (!element || hasClass(element, className)) {
      return;
    }
    element.className = element.className ? element.className + " " + className : className;
  }

  function removeClass(element, className) {
    if (!element) {
      return;
    }
    element.className = (" " + (element.className || "") + " ")
      .replace(" " + className + " ", " ")
      .replace(/^\s+|\s+$/g, "");
  }

  function toggleClass(element, className, enabled) {
    if (enabled) {
      addClass(element, className);
    } else {
      removeClass(element, className);
    }
  }

  function setHidden(element, hidden) {
    if (!element) {
      return;
    }
    element.hidden = hidden;
    if (hidden) {
      element.setAttribute("hidden", "");
    } else {
      element.removeAttribute("hidden");
    }
  }

  function isHidden(element) {
    return !element || element.hidden || element.getAttribute("hidden") !== null;
  }

  function setText(element, text) {
    if (element) {
      element.textContent = text;
    }
  }

  function setMessage(text, type) {
    setText(message, text || "");
    toggleClass(message, "is-error", type === "error");
    toggleClass(message, "is-success", type === "success");
  }

  function setView(view) {
    var i;
    for (i = 0; i < forms.length; i += 1) {
      var form = forms[i];
      var isActive = form.getAttribute("data-member-view") === view;
      setHidden(form, !isActive);
      toggleClass(form, "is-active", isActive);
      form.setAttribute("aria-hidden", isActive ? "false" : "true");
    }
    if (dialog) {
      dialog.setAttribute("data-active-view", view);
    }
    setText(title, titles[view] || "会员中心");
    setText(description, descriptions[view] || "");
    setText(eyebrow, view === "catalog-email" ? "Catalog" : "Member");
    setMessage("");
  }

  function openModal(view, options) {
    var firstInput;
    view = view || "login";
    options = options || {};
    setView(view);
    setHidden(modal, false);
    document.body.style.overflow = "hidden";
    firstInput = modal.querySelector('[data-member-view="' + view + '"] input');
    if (firstInput) {
      firstInput.focus();
    }
  }

  function closeModal() {
    setHidden(modal, true);
    document.body.style.overflow = "";
    setMessage("");
  }

  function updateMemberUi() {
    var status = menu.querySelector("[data-member-status]");
    var initial = menu.querySelector("[data-member-initial]");
    menu.setAttribute("data-state", state.loggedIn ? "logged-in" : "guest");
    if (state.loggedIn && state.member) {
      setText(status, (state.member.name || state.member.email || "会员") + " · " + (state.member.company || ""));
      setText(initial, state.member.initial || "R");
    } else {
      setText(status, "未登录");
      setText(initial, "R");
    }
  }

  function mergeData(base, extra) {
    var key;
    var output = {};
    base = base || {};
    extra = extra || {};
    for (key in base) {
      if (Object.prototype.hasOwnProperty.call(base, key)) {
        output[key] = base[key];
      }
    }
    for (key in extra) {
      if (Object.prototype.hasOwnProperty.call(extra, key)) {
        output[key] = extra[key];
      }
    }
    return output;
  }

  function encodeBody(data) {
    var pairs = [];
    var key;
    for (key in data) {
      if (Object.prototype.hasOwnProperty.call(data, key) && data[key] !== undefined && data[key] !== null) {
        pairs.push(encodeURIComponent(key) + "=" + encodeURIComponent(data[key]));
      }
    }
    return pairs.join("&");
  }

  function parseAjaxResponse(text) {
    try {
      return JSON.parse(text);
    } catch (error) {
      return {
        success: false,
        data: {
          message: "服务器返回格式异常，请刷新页面后重试。"
        }
      };
    }
  }

  function api(action, data, onSuccess, onFailure) {
    var xhr;
    var body;
    var payload;
    onSuccess = onSuccess || function () {};
    onFailure = onFailure || function () {};

    if (!config.ajaxUrl || !config.nonce) {
      onFailure(new Error("该功能需要在正式 WordPress 网站中使用。"));
      return;
    }

    payload = mergeData(
      {
        action: action,
        nonce: config.nonce
      },
      data || {}
    );
    body = encodeBody(payload);
    xhr = new XMLHttpRequest();
    xhr.open("POST", config.ajaxUrl, true);
    xhr.withCredentials = true;
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8");
    xhr.onreadystatechange = function () {
      var response;
      var errorMessage;
      if (xhr.readyState !== 4) {
        return;
      }
      response = parseAjaxResponse(xhr.responseText || "");
      if (!response || !response.success) {
        errorMessage =
          response && response.data && response.data.message
            ? response.data.message
            : "操作失败，请稍后重试。";
        onFailure(new Error(errorMessage));
        return;
      }
      onSuccess(response.data || {});
    };
    xhr.onerror = function () {
      onFailure(new Error("网络连接失败，请检查网络后重试。"));
    };
    xhr.send(body);
  }

  function refreshMember() {
    if (!config.ajaxUrl || !config.nonce) {
      updateMemberUi();
      return;
    }

    api(
      "rvision_member_me",
      {},
      function (data) {
        state.loggedIn = Boolean(data.loggedIn);
        state.member = data.member || null;
        updateMemberUi();
      },
      function () {
        state.loggedIn = false;
        state.member = null;
        updateMemberUi();
      }
    );
  }

  function downloadCatalog() {
    openModal("catalog-email");
  }

  function formData(form) {
    var data = {};
    var elements = form.elements || [];
    var i;
    for (i = 0; i < elements.length; i += 1) {
      var element = elements[i];
      if (!element.name || element.disabled) {
        continue;
      }
      if ((element.type === "checkbox" || element.type === "radio") && !element.checked) {
        continue;
      }
      data[element.name] = element.value;
    }
    return data;
  }

  function submitForm(form, action, onSuccess, extraData) {
    var submit = form.querySelector("[type='submit']");
    if (submit) {
      submit.disabled = true;
    }
    setMessage("处理中...");
    api(
      action,
      mergeData(formData(form), extraData || {}),
      function (data) {
        if (submit) {
          submit.disabled = false;
        }
        onSuccess(data, form);
      },
      function (error) {
        if (submit) {
          submit.disabled = false;
        }
        setMessage(error.message || "操作失败，请稍后重试。", "error");
      }
    );
  }

  addEvent(menu.querySelector("[data-member-toggle]"), "click", function () {
    toggleClass(menu, "is-open", !hasClass(menu, "is-open"));
  });

  addEvent(document, "click", function (event) {
    var target = getEventTarget(event);
    if (target && !menu.contains(target)) {
      removeClass(menu, "is-open");
    }
  });

  (function bindOpenButtons() {
    var buttons = document.querySelectorAll("[data-member-open]");
    var i;
    for (i = 0; i < buttons.length; i += 1) {
      addEvent(buttons[i], "click", function () {
        openModal(this.getAttribute("data-member-open") || "login");
      });
    }
  })();

  (function bindCloseButtons() {
    var buttons = modal.querySelectorAll("[data-member-close]");
    var i;
    for (i = 0; i < buttons.length; i += 1) {
      addEvent(buttons[i], "click", closeModal);
    }
  })();

  addEvent(document, "keydown", function (event) {
    var key = event.key || event.keyCode;
    if ((key === "Escape" || key === 27) && !isHidden(modal)) {
      closeModal();
    }
  });

  (function bindCatalogButtons() {
    var buttons = document.querySelectorAll("[data-catalog-request]");
    var i;
    for (i = 0; i < buttons.length; i += 1) {
      addEvent(buttons[i], "click", function (event) {
        preventDefault(event);
        downloadCatalog();
      });
    }
  })();

  addEvent(menu.querySelector("[data-member-logout]"), "click", function () {
    function clearMember() {
      state.loggedIn = false;
      state.member = null;
      updateMemberUi();
    }
    api("rvision_member_logout", {}, clearMember, clearMember);
  });

  addEvent(modal.querySelector('[data-member-view="login"]'), "submit", function (event) {
    preventDefault(event);
    submitForm(this, "rvision_member_login", function (data) {
      state.loggedIn = true;
      state.member = data.member;
      updateMemberUi();
      closeModal();
    });
  });

  addEvent(modal.querySelector('[data-member-view="catalog-email"]'), "submit", function (event) {
    preventDefault(event);
    submitForm(this, "rvision_catalog_request", function (data, form) {
      form.reset();
      setMessage(data.message || "产品目录已发送，请检查邮箱。", "success");
    });
  });

  addEvent(modal.querySelector('[data-member-view="register"]'), "submit", function (event) {
    preventDefault(event);
    submitForm(this, "rvision_member_register", function (data, form) {
      state.registerEmail = data.email || form.elements.email.value;
      setView("register-code");
      setMessage("验证码已发送，请输入邮箱中的 6 位验证码。", "success");
    });
  });

  addEvent(modal.querySelector('[data-member-view="register-code"]'), "submit", function (event) {
    preventDefault(event);
    submitForm(
      this,
      "rvision_member_verify_registration",
      function (payload) {
        state.loggedIn = true;
        state.member = payload.member;
        updateMemberUi();
        closeModal();
      },
      { email: state.registerEmail }
    );
  });

  addEvent(modal.querySelector('[data-member-view="reset-request"]'), "submit", function (event) {
    preventDefault(event);
    submitForm(this, "rvision_member_request_password_reset", function (data, form) {
      state.resetEmail = form.elements.email.value;
      setView("reset-code");
      setMessage(data.message || "验证码已发送，请检查邮箱。", "success");
    });
  });

  addEvent(modal.querySelector('[data-member-view="reset-code"]'), "submit", function (event) {
    preventDefault(event);
    submitForm(
      this,
      "rvision_member_reset_password",
      function () {
        setView("login");
        setMessage("密码已重置，请使用新密码登录。", "success");
      },
      { email: state.resetEmail }
    );
  });

  function getQueryParam(name) {
    var parts = window.location.search ? window.location.search.substring(1).split("&") : [];
    var i;
    for (i = 0; i < parts.length; i += 1) {
      var pair = parts[i].split("=");
      if (decodeURIComponent(pair[0].replace(/\+/g, " ")) === name) {
        return decodeURIComponent((pair[1] || "").replace(/\+/g, " "));
      }
    }
    return "";
  }

  if (getQueryParam("catalog") === "request" || getQueryParam("next") === "catalog-download") {
    openModal("catalog-email");
  } else if (getQueryParam("member") === "login") {
    openModal("login");
  } else if (getQueryParam("member") === "register") {
    openModal("register");
  }

  refreshMember();
  window.__rvisionMemberReady = true;
  document.documentElement.setAttribute("data-member-ready", "true");
})();
