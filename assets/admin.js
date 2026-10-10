export function createPage(host) {
  const {React, components: C, request, toast, icons} = host;
  const h = React.createElement, base = '/admin/plugins/flex/econt';
  function SettingsPage({navigate}) {
    const [values, setValues] = React.useState(null);
    const [busy, setBusy] = React.useState(false);
    const [testing, setTesting] = React.useState(false);
    const [error, setError] = React.useState('');
    const [testResult, setTestResult] = React.useState(null);
    React.useEffect(() => {
      request(base + '/settings').then((d) => setValues(d.settings)).catch((e) => setError(e.message));
    }, []);
    function update(patch) {
      setValues((current) => ({...current, ...patch}));
    }
    async function save(event) {
      event.preventDefault();
      setBusy(true);
      setError('');
      setTestResult(null);
      try {
        const body = {...values};
        if (!body.test_password) delete body.test_password;
        if (!body.live_password) delete body.live_password;
        const data = await request(base + '/settings', 'PUT', body);
        setValues(data.settings);
        toast?.('Настройките на Еконт са запазени.');
      } catch (e) {
        setError(e.message);
      } finally {
        setBusy(false);
      }
    }
    async function testConnection() {
      setTesting(true);
      setTestResult(null);
      setError('');
      try {
        const data = await request(base + '/test-connection', 'POST', {});
        setTestResult(data);
        toast?.('Връзката с Еконт е успешна (' + data.mode + ').');
      } catch (e) {
        setError(e.message);
      } finally {
        setTesting(false);
      }
    }
    const modeLabel = values?.mode === 'live' ? 'Live' : 'Test';
    return h(React.Fragment, null,
      h('div', {className: 'react-page-heading'},
        h('div', null,
          h('h1', null, 'Еконт'),
          h(C.Breadcrumbs, {
            items: [{label: 'Еконт'}],
            onHomeClick: () => navigate?.('/'),
          }),
          values && h('p', {className: 'econt-mode-badge is-' + values.mode}, 'Активен режим: ' + modeLabel))),
      error && h('p', {role: 'alert'}, error),
      testResult && h('p', {role: 'status', className: 'econt-test-result'}, 'Тестът успя · ' + testResult.base_url + ' · държави: ' + testResult.countries),
      !values
        ? h('p', {role: 'status'}, error ? 'Настройките не могат да бъдат заредени.' : 'Зареждане…')
        : h('form', {onSubmit: save, className: 'react-edit-form econt-edit-form'},
            h('fieldset', {disabled: busy || testing},
              h(C.CollapsibleSection, {title: 'Общи', icon: icons.Settings, storageKey: 'econt-general', defaultOpen: true},
                h('div', {className: 'react-form-grid'},
                  h('p', {className: 'react-field-hint'}, 'За да се ползва Еконт в checkout, в Магазин → Настройки → Доставка изберете „Еконт (автоматично изчисление)“. Фиксираната цена се управлява оттам.'),
                  h(C.CheckboxField, {label: 'Включена доставка с Еконт', checked: !!values.enabled, onChange: (e) => update({enabled: e.target.checked}), description: 'Показва методите на Еконт в checkout. Нужни са режим „Еконт“ в настройките на магазина и попълнен достъп за активния режим.'}),
                  h(C.CheckboxField, {label: 'До офис / автомат', checked: !!values.office_enabled, onChange: (e) => update({office_enabled: e.target.checked}), description: 'Клиентът може да получи пратката в офис или автомат на Еконт.'}),
                  h(C.CheckboxField, {label: 'До адрес', checked: !!values.address_enabled, onChange: (e) => update({address_enabled: e.target.checked}), description: 'Клиентът може да получи пратката на адрес.'}),
                  h(C.CheckboxField, {label: 'Автоматична товарителница при поръчка', checked: !!values.auto_label, onChange: (e) => update({auto_label: e.target.checked}), description: 'След успешна поръчка с Еконт се създава товарителница. Без тази опция цената се калкулира, но товарителница не се издава.'}),
                  h('label', {className: 'react-form-field'}, 'API режим',
                    h(C.DropdownMenu, {
                      ariaLabel: 'API режим',
                      trigger: h(React.Fragment, null, h('span', null, modeLabel), h(C.DropdownChevron)),
                      triggerClassName: 'react-form-dropdown',
                    },
                      h(C.DropdownOption, {selected: values.mode === 'test', onClick: () => update({mode: 'test'})}, 'Test (demo)'),
                      h(C.DropdownOption, {selected: values.mode === 'live', onClick: () => update({mode: 'live'})}, 'Live')),
                    h('small', {className: 'react-field-hint'}, 'Test ползва demo.econt.com. Live ползва реалния акаунт. При грешка в Live поръчката се блокира. Демо цена се допуска само в Test.')),
                  h('label', {className: 'react-form-field'}, 'Стандартно тегло (кг)',
                    h(C.Input, {type: 'number', min: 0.01, step: '0.01', value: values.default_weight, onChange: (e) => update({default_weight: Number(e.target.value)})}),
                    h('small', {className: 'react-field-hint'}, 'Тегло за калкулация, докато продуктите нямат собствено. Допустимо е между 0.01 и 1000 кг.')))),
              h(C.CollapsibleSection, {title: 'Test достъп', icon: icons.Settings, storageKey: 'econt-test', defaultOpen: values.mode === 'test'},
                h('div', {className: 'react-form-grid'},
                  h('label', {className: 'react-form-field'}, 'Потребител (test)',
                    h(C.Input, {value: values.test_username || '', onChange: (e) => update({test_username: e.target.value})}),
                    h('small', {className: 'react-field-hint'}, 'Потребител за demo.econt.com. Официалното демо е iasp-dev.')),
                  h('label', {className: 'react-form-field'}, 'Парола (test)' + (values.test_password_set ? ' · запазена' : ''),
                    h(C.Input, {type: 'password', value: values.test_password || '', placeholder: values.test_password_set ? '••••••••' : '', onChange: (e) => update({test_password: e.target.value})}),
                    h('small', {className: 'react-field-hint'}, 'Парола за тестовия акаунт. Демото е 1Asp-dev. Празно поле запазва вече записаната парола.')))),
              h(C.CollapsibleSection, {title: 'Live достъп', icon: icons.Settings, storageKey: 'econt-live', defaultOpen: values.mode === 'live'},
                h('div', {className: 'react-form-grid'},
                  h('label', {className: 'react-form-field'}, 'Потребител (live)',
                    h(C.Input, {value: values.live_username || '', onChange: (e) => update({live_username: e.target.value})}),
                    h('small', {className: 'react-field-hint'}, 'Потребител за реалния акаунт в Еконт. Ползва се, когато режимът е Live.')),
                  h('label', {className: 'react-form-field'}, 'Парола (live)' + (values.live_password_set ? ' · запазена' : ''),
                    h(C.Input, {type: 'password', value: values.live_password || '', placeholder: values.live_password_set ? '••••••••' : '', onChange: (e) => update({live_password: e.target.value})}),
                    h('small', {className: 'react-field-hint'}, 'Парола за реалния акаунт. Празно поле запазва вече записаната парола.')))),
              h(C.CollapsibleSection, {title: 'Подател', icon: icons.Settings, storageKey: 'econt-sender', defaultOpen: true},
                h('div', {className: 'react-form-grid'},
                  h('label', {className: 'react-form-field'}, 'Име',
                    h(C.Input, {value: values.sender_name || '', onChange: (e) => update({sender_name: e.target.value})}),
                    h('small', {className: 'react-field-hint'}, 'Име на подателя в калкулацията и товарителницата. Ако е празно, се ползва „Demo магазин“.')),
                  h('label', {className: 'react-form-field'}, 'Телефон',
                    h(C.Input, {value: values.sender_phone || '', onChange: (e) => update({sender_phone: e.target.value})}),
                    h('small', {className: 'react-field-hint'}, 'Телефон на подателя. Ако е празно, се ползва +359888000000.')),
                  h('label', {className: 'react-form-field'}, 'Код на офис (изпращане)',
                    h(C.Input, {value: values.sender_office_code || '', onChange: (e) => update({sender_office_code: e.target.value})}),
                    h('small', {className: 'react-field-hint'}, 'Ако е попълнен, пратките тръгват от този офис вместо от адрес.')),
                  h('label', {className: 'react-form-field'}, 'Град',
                    h(C.Input, {value: values.sender_city || '', onChange: (e) => update({sender_city: e.target.value})}),
                    h('small', {className: 'react-field-hint'}, 'Град на подателя, когато няма код на офис. Ако е празно, се ползва София.')),
                  h('label', {className: 'react-form-field'}, 'Пощенски код',
                    h(C.Input, {value: values.sender_postcode || '', onChange: (e) => update({sender_postcode: e.target.value})}),
                    h('small', {className: 'react-field-hint'}, 'Пощенски код на подателя. Ако е празно, се ползва 1000.')),
                  h('label', {className: 'react-form-field'}, 'Адрес',
                    h(C.Input, {value: values.sender_address || '', onChange: (e) => update({sender_address: e.target.value})}),
                    h('small', {className: 'react-field-hint'}, 'Улица и номер, когато пратките тръгват от адрес. Ако е празно, се ползва ул. Витоша 1.')))),
              h('div', {className: 'react-form-actions'},
                h(C.LoadingButton, {type: 'button', loading: testing, disabled: testing || busy, onClick: testConnection}, 'Тест на връзката'),
                h(C.LoadingButton, {type: 'submit', loading: busy, disabled: busy || testing, icon: h(icons.Save)}, 'Запази настройките')))));
  }
  return function EcontPage({navigate}) {
    return h(React.Fragment, null,
      h('link', {rel: 'stylesheet', href: '/extensions/flex/econt/assets/assets/econt.css?v=2'}),
      h(SettingsPage, {navigate}));
  };
}
