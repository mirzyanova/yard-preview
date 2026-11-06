function bindContactForm(){
  var form=app.querySelector('#yardContactForm'); if(!form)return;
  var status=form.querySelector('.contact-form-status');
  var button=form.querySelector('button[type="submit"]');
  var ready=yardLegal&&yardLegal.contactEnabled;
  button.innerHTML='Отправить сообщение '+iconSvg('up-right','inline-arrow');
  button.disabled=!ready;
  form.querySelector('.contact-form-note').innerHTML=ready?'Сообщение будет отправлено на почту бюро. <a href="#/privacy">Политика обработки персональных данных</a>.':'Серверная форма пока не включена. Вы можете написать на yard.bureau@mail.ru.';
  form.querySelector('.contact-consent span').innerHTML='Даю <a href="#/consent" target="_blank" rel="noopener">согласие на обработку персональных данных</a> для ответа на обращение.';
  var honeypot=document.createElement('input');
  honeypot.type='text';honeypot.name='website';honeypot.tabIndex=-1;honeypot.autocomplete='off';honeypot.setAttribute('aria-hidden','true');honeypot.hidden=true;form.appendChild(honeypot);
  var token=null;
  async function refreshToken(){
    token=null;button.disabled=true;
    try{token=await yardRequest('contact-token');if(form.isConnected)button.disabled=false;}
    catch(error){if(form.isConnected)status.textContent='Форма сейчас недоступна. Напишите на yard.bureau@mail.ru.';}
  }
  if(ready)refreshToken();
  form.addEventListener('submit',async function(event){
    event.preventDefault();if(!token||button.disabled||!form.reportValidity())return;
    button.disabled=true;status.textContent='Отправляем сообщение…';
    try{
      var result=await yardRequest('contact',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
        name:form.elements.senderName.value.trim(),email:form.elements.senderEmail.value.trim(),message:form.elements.senderMessage.value.trim(),
        consent:form.elements.consent.checked,consentVersion:token.version,token:token.token,website:honeypot.value
      })});
      if(result.ok!==true)throw new Error('Сервер не подтвердил отправку.');
      status.textContent=result.message;form.reset();token=null;
      // Keep the submit button disabled after success to prevent accidental duplicates.
    }catch(error){status.textContent=error.name==='AbortError'?'Сервер не ответил вовремя. Отправка не подтверждена; напишите на почту бюро.':error.message;await refreshToken();}
  });
}
function yardLegalPage(consent){
  var legal=yardLegal;
  var html='<section class="privacy"><h1>'+(consent?'Согласие на обработку персональных данных':'Политика обработки персональных данных')+'</h1>';
  if(!legal||!legal.ready){
    html+='<p>Серверная форма обратной связи пока отключена. Сведения об операторе персональных данных будут опубликованы до её включения.</p>';
  }else{
    html+='<p>Оператор: '+yardEscape(legal.operator)+'. Адрес: '+yardEscape(legal.address)+'.'+(legal.inn?' ИНН: '+yardEscape(legal.inn)+'.':'')+' Контакт для обращений и отзыва согласия: <a href="mailto:yard.bureau@mail.ru">yard.bureau@mail.ru</a>.</p>';
    if(consent){
      html+='<p>Я свободно и в своём интересе даю указанному оператору согласие на обработку моего имени, адреса электронной почты и сведений, которые я включу в сообщение, исключительно для рассмотрения обращения, связи со мной и подготовки ответа.</p><p>Разрешённые действия: сбор, запись, систематизация, хранение, уточнение, использование, передача почтовому сервису для доставки обращения, блокирование, удаление и уничтожение. Обработка выполняется с использованием средств автоматизации и без них.</p><p>Согласие действует до ответа на обращение и последующего хранения переписки не дольше '+Number(legal.retentionDays)+' дней, если иной срок не предусмотрен законом. Я могу отозвать согласие письмом оператору. После достижения цели или отзыва данные удаляются в сроки, установленные законом, если отсутствует иное законное основание обработки.</p><p>Это согласие не распространяется на рекламу, рассылки или публикацию данных. Версия документа: '+yardEscape(legal.version)+'.</p>';
    }else{
      html+='<p>Форма предназначена для связи с бюро. Имя, email и текст обращения обрабатываются на основании отдельного согласия посетителя. Не указывайте в сообщении специальные категории персональных данных, сведения о здоровье, документы или данные других людей.</p><p>Сайт, система управления проектами, медиабиблиотека и первичная обработка данных расположены на хостинге Timeweb в России. Сообщения направляются в почтовый ящик бюро на Mail.ru для рассмотрения и ответа. Данные не используются для рекламы и не публикуются. Вложения через форму не принимаются.</p><p>Срок хранения обращений — не более '+Number(legal.retentionDays)+' дней после достижения цели, если закон не устанавливает иное. Посетитель вправе запросить сведения об обработке, уточнение, блокирование или удаление данных, отозвать согласие и обратиться в Роскомнадзор.</p><p>Для защиты формы используются проверка полей, одноразовый подписанный токен и краткосрочный счётчик обращений на основе криптографического отпечатка IP. Тексты сообщений не записываются в базу WordPress. Факт согласия и версия документа включаются в письмо оператору. Доступ редакторов ограничен проектами и фотографиями.</p>';
    }
  }
  if(!consent)html+='<p>Рекламные и аналитические cookie не используются. Уведомление о cookie показывается при каждом новом открытии сайта; его закрытие не сохраняется. Для входа сотрудников в административную панель WordPress используются необходимые служебные cookie.</p>';
  html+='<p><a class="text-link" href="#/">'+iconSvg('back','inline-arrow')+' На главную</a></p></section>';
  app.innerHTML=html;
}
function privacy(){yardLegalPage(false)}
