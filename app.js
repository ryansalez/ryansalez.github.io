const STORE_KEY = 'eletro_v2_store';
const DEFAULT = { products: [{"id": "p1", "name": "Smartphone Nova X 5G 256GB", "price": 2999.9, "oldPrice": 3499.9, "rating": 4.7, "brand": "Nova", "category": "Smartphones", "image": "assets/p1.svg", "description": "Tela AMOLED 6.7” 120Hz, câmera 64MP, bateria 5000mAh."}, {"id": "p2", "name": "Notebook Falcon Pro i7 16GB 512GB", "price": 5299.0, "oldPrice": 5999.0, "rating": 4.6, "brand": "Falcon", "category": "Notebooks", "image": "assets/p2.svg", "description": "Intel i7, 16GB RAM, SSD 512GB NVMe, tela 15.6” FHD."}, {"id": "p3", "name": "Fone Bluetooth ANC AirBeat Max", "price": 699.9, "oldPrice": 899.9, "rating": 4.4, "brand": "AirBeat", "category": "Audio", "image": "assets/p3.svg", "description": "Cancelamento de ruído ativo, 40h de bateria."}, {"id": "p4", "name": "Smart TV 55\\\" 4K Quantum", "price": 3299.0, "oldPrice": 3999.0, "rating": 4.5, "brand": "Quantum", "category": "TVs", "image": "assets/p4.svg", "description": "Painel QLED 4K HDR10+, Apps integrados."}, {"id": "p5", "name": "Mouse Gamer Flux RGB 12.8K DPI", "price": 199.9, "oldPrice": 249.9, "rating": 4.3, "brand": "Flux", "category": "Perifericos", "image": "assets/p5.svg", "description": "Sensor óptico 12.800 DPI, 7 botões programáveis."}, {"id": "p6", "name": "Console Nitro One 1TB", "price": 4299.0, "oldPrice": 4599.0, "rating": 4.8, "brand": "Nitro", "category": "Consoles", "image": "assets/p6.svg", "description": "Ray tracing, SSD 1TB, 4K 120fps."}] };

function loadStore(){ try{ return JSON.parse(localStorage.getItem(STORE_KEY)) || DEFAULT; }catch(e){return DEFAULT;} }
function saveStore(s){ localStorage.setItem(STORE_KEY, JSON.stringify(s)); }
function getCart(){ return JSON.parse(localStorage.getItem('cart_v2')||'[]'); }
function setCart(c){ localStorage.setItem('cart_v2', JSON.stringify(c)); }
function getUser(){ return JSON.parse(localStorage.getItem('user_v2')||'null'); }
function setUser(u){ localStorage.setItem('user_v2', JSON.stringify(u)); }

function money(v){ return v.toLocaleString('pt-BR', {style:'currency', currency:'BRL'}); }

function renderHeader(){ 
  document.querySelectorAll('[data-cart-count]').forEach(el=>el.textContent = getCart().reduce((a,b)=>a+b.qty,0));
  const user = getUser();
  document.querySelectorAll('[data-auth-area]').forEach(el=> el.innerHTML = user ? `<div class='flex'><a class='btn' href='conta.html'>Minha Conta</a><button id='logout' class='btn'>Sair</button></div>` : `<a class='btn' href='login.html'>Entrar</a>`);
  document.getElementById('logout')?.addEventListener('click', ()=>{ setUser(null); notify('Logout'); renderHeader(); });
}

function init(){
  renderHeader();
  document.querySelectorAll('[data-open-cart]').forEach(b=>b.addEventListener('click', ()=>location.href='carrinho.html'));
  setupMobile();
  setupTheme();
}

function setupTheme() {
  const themeToggle = document.getElementById('theme-toggle');
  const currentTheme = localStorage.getItem('theme') || 'dark';
  document.body.classList.toggle('light-theme', currentTheme === 'light');
  themeToggle.textContent = currentTheme === 'light' ? '🌙' : '☀️';

  themeToggle.addEventListener('click', () => {
    const isLight = document.body.classList.toggle('light-theme');
    localStorage.setItem('theme', isLight ? 'light' : 'dark');
    themeToggle.textContent = isLight ? '🌙' : '☀️';
  });
}

// Mobile nav
function setupMobile(){ const btn = document.getElementById('hamb'); const nav = document.getElementById('mobileNav'); btn?.addEventListener('click', ()=>{ nav.classList.toggle('hidden'); }); }

// Products render with filters, sorting, search
function pageIndex(){ const s = loadStore(); const container = document.getElementById('produtos'); if(!container) return;
  const query = (new URLSearchParams(location.hash.replace('#','?'))).get('q') || '';
  const params = new URLSearchParams(location.search);
  const cat = params.get('cat') || 'Todos';
  const sort = params.get('sort') || 'popular';
  const q = document.getElementById('q'); if(q) q.value = query;

  let list = s.products.slice();
  if(cat && cat!=='Todos') list = list.filter(p=>p.category===cat);
  if(query) list = list.filter(p=>p.name.toLowerCase().includes(query.toLowerCase()) || p.description.toLowerCase().includes(query.toLowerCase()));
  if(sort==='price_asc') list.sort((a,b)=>a.price-b.price);
  if(sort==='price_desc') list.sort((a,b)=>b.price-a.price);
  if(sort==='rating') list.sort((a,b)=>b.rating-a.rating);

  container.innerHTML = list.map(p=>`<article class='card' role='article' aria-label='${p.name}'>
    <div class='thumb'>
      <img loading='lazy' src='${p.image}' alt='${p.name}'/>
      <button class='quick-view' onclick="openQuickView('${p.id}')" aria-label='Visualizar'>👁️</button>
      ${p.oldPrice ? `<div class='badge' style='position:absolute;top:12px;left:12px;background:var(--accent);color:white;'>${Math.round((p.oldPrice-p.price)/p.oldPrice*100)}% OFF</div>` : ''}
    </div>
    <div class='body'>
      <div class='badge'>${p.brand}</div>
      <h3><a href='produto.html?id=${p.id}'>${p.name}</a></h3>
      <div class='rating'>${'★'.repeat(Math.round(p.rating))}${'☆'.repeat(5-Math.round(p.rating))} ${p.rating}</div>
      <div class='price'>
        <div class='now'>${money(p.price)}</div> 
        ${p.oldPrice ? `<div class='old'>${money(p.oldPrice)}</div>` : ''}
      </div>
      <div class='toolbar' style='margin-top:12px'>
        <button class='btn primary' onclick="addToCart('${p.id}',1)">Adicionar</button>
      </div>
    </div></article>`).join('');
}

// Quick view modal
function openQuickView(id){ const s = loadStore(); const p = s.products.find(x=>x.id===id); if(!p) return;
  const modal = document.getElementById('quickModal'); modal.querySelector('[data-name]').textContent = p.name;
  modal.querySelector('[data-img]').src = p.image; modal.querySelector('[data-price]').textContent = money(p.price);
  modal.querySelector('[data-desc]').textContent = p.description; modal.classList.add('show');
  modal.querySelector('.close')?.addEventListener('click', ()=>modal.classList.remove('show'));
  modal.querySelector('[data-add]').onclick = ()=>{ addToCart(p.id,1); modal.classList.remove('show'); };
}

// Cart
function addToCart(id, qty=1){
  const cart = getCart();
  const i = cart.findIndex(x=>x.id===id);
  if(i>-1) cart[i].qty += qty; else cart.push({id,qty});
  setCart(cart);
  renderHeader();
  // If we're on the cart page, refresh the cart display
  if (document.body.getAttribute('data-page') === 'carrinho') {
    pageCarrinho();
  }
  notify('Adicionado ao carrinho');
}
function removeFromCart(id){ const cart = getCart().filter(x=>x.id!==id); setCart(cart); renderHeader(); pageCarrinho(); }
function updateQty(id, delta){ const cart = getCart(); const i = cart.findIndex(x=>x.id===id); if(i>-1){ cart[i].qty += delta; if(cart[i].qty<=0) cart.splice(i,1); setCart(cart); renderHeader(); pageCarrinho(); }}

// Pages
function pageProduto(){ const params = new URLSearchParams(location.search); const id=params.get('id'); const s=loadStore(); const p=s.products.find(x=>x.id===id); if(!p) return;
  document.querySelector('[data-name]').textContent=p.name;
  document.querySelector('[data-img]').src=p.image;
  document.querySelector('[data-price]').textContent=money(p.price);
  document.querySelector('[data-desc]').textContent=p.description;
  document.querySelector('[data-add]').onclick=()=>addToCart(p.id,1);
  document.querySelector('[data-brand]').textContent=p.brand;
  document.querySelector('[data-rating]').innerHTML = `${'★'.repeat(Math.round(p.rating))}${'☆'.repeat(5-Math.round(p.rating))} ${p.rating}`;
  document.querySelector('[data-brand-spec]').textContent=p.brand;
  document.querySelector('[data-cat-spec]').textContent=p.category;
}

function pageCarrinho(){
  const s=loadStore();
  const body=document.getElementById('cart-body');
  if(!body) return;
  const cart=getCart();
  let total=0;
  
  // Update cart count badge
  const cartCount = cart.reduce((a,b)=>a+b.qty,0);
  const cartCountBadge = document.getElementById('cart-count-badge');
  if (cartCountBadge) {
    cartCountBadge.textContent = cartCount + (cartCount === 1 ? ' item' : ' itens');
  }
  
  // Update header cart count
  const headerCartCounts = document.querySelectorAll('[data-cart-count]');
  headerCartCounts.forEach(el => {
    el.textContent = cartCount;
  });
  
  if (cart.length === 0) {
    body.innerHTML = `<div class="text-center p-lg">
      <div style="font-size: 4rem; margin-bottom: var(--spacing-md);">🛒</div>
      <h3>Seu carrinho está vazio</h3>
      <p class="text-muted mb-lg">Adicione produtos para continuar com a compra</p>
      <a href="index.html" class="btn primary">Continuar Comprando</a>
    </div>`;
    
    // Update summary values
    document.getElementById('subtotal').textContent = money(0);
    document.getElementById('shipping').textContent = 'Grátis';
    document.getElementById('discount').textContent = '- ' + money(0);
    document.getElementById('cart-total').textContent = money(0);
  } else {
    body.innerHTML=cart.map(item=>{
      const p=s.products.find(x=>x.id===item.id);
      if (!p) return '';
      const sub=p.price*item.qty;
      total+=sub;
      return `<div class="card mb-md">
        <div class="body">
          <div class="grid" style="grid-template-columns: 80px 1fr auto; gap: var(--spacing-lg); align-items: center;">
            <div class="thumb" style="height: 80px; padding: var(--spacing-sm);">
              <img src="${p.image}" alt="${p.name}" style="max-width: 100%; max-height: 60px;">
            </div>
            <div>
              <h3 class="mt-0 mb-xs">${p.name}</h3>
              <div class="badge mb-sm">${p.brand}</div>
              <div style="font-weight: var(--font-weight-bold); color: var(--primary);">${money(p.price)}</div>
            </div>
            <div class="flex gap-sm" style="align-items: center;">
              <button class="btn icon-only" onclick="updateQty('${p.id}',-1)">-</button>
              <span style="min-width: 30px; text-align: center;">${item.qty}</span>
              <button class="btn icon-only" onclick="updateQty('${p.id}',1)">+</button>
              <button class="btn icon-only danger" onclick="removeFromCart('${p.id}')" style="margin-left: var(--spacing-md);">🗑️</button>
            </div>
          </div>
        </div>
      </div>`;
    }).join('');
    
    // Update summary values
    document.getElementById('subtotal').textContent = money(total);
    document.getElementById('shipping').textContent = 'Grátis';
    document.getElementById('discount').textContent = '- ' + money(0);
    document.getElementById('cart-total').textContent = money(total);
  }
  document.getElementById('checkout-btn').onclick=()=>location.href='checkout.html';
}

function pageCheckout(){
  const user=getUser();
  if(!user) return location.href='login.html?next=checkout.html';
  const cart=getCart();
  const s=loadStore();
  let total=0;
  document.getElementById('resumo').innerHTML = cart.map(item=>{
    const p=s.products.find(x=>x.id===item.id);
    if (!p) return '';
    const sub=p.price*item.qty;
    total+=sub;
    return `<div class="card mb-sm">
      <div class="body">
        <div class="flex-between">
          <div>
            <h4 class="mt-0 mb-xs">${p.name}</h4>
            <div class="text-muted">Quantidade: ${item.qty}</div>
          </div>
          <div style="font-weight: var(--font-weight-bold);">${money(sub)}</div>
        </div>
      </div>
    </div>`
  }).join('');
  document.getElementById('total').textContent=money(total);
  document.getElementById('subtotal').textContent=money(total);
  document.getElementById('discount').textContent='- ' + money(0);
  document.getElementById('finalizar').onclick=()=>{
    const cardForm = document.getElementById('card-form');
    const paymentMethod = document.querySelector('[data-payment].active').getAttribute('data-payment');

    if (paymentMethod === 'card') {
      const cpf = cardForm.elements.card_cpf.value;
      const cardNumber = cardForm.elements.card_number.value.replace(/\s/g, '');

      if (!validateCPF(cpf)) {
        return notify('CPF inválido', 'error');
      }
      if (!validateCardNumber(cardNumber)) {
        return notify('Número de cartão inválido', 'error');
      }
    }

    const orders = JSON.parse(localStorage.getItem('orders_v2')||'[]');
    const pedido={id:'PED'+Date.now(),user,total,items:cart,createdAt:new Date().toISOString(),status:'Em processamento'};
    orders.push(pedido);
    localStorage.setItem('orders_v2',JSON.stringify(orders));
    setCart([]);
    location.href='confirmacao.html';
  }

  const paymentButtons = document.querySelectorAll('[data-payment]');
  paymentButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      paymentButtons.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const paymentMethod = btn.getAttribute('data-payment');
      document.getElementById('card-form').classList.toggle('hidden', paymentMethod !== 'card');
      document.getElementById('pix-info').classList.toggle('hidden', paymentMethod !== 'pix');
      document.getElementById('boleto-info').classList.toggle('hidden', paymentMethod !== 'boleto');
    });
  });

  const cepInput = document.querySelector('input[name="zip"]');
  cepInput?.addEventListener('blur', async (e) => {
    const cep = e.target.value.replace(/\D/g, '');
    if (cep.length === 8) {
      const res = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
      const data = await res.json();
      if (!data.erro) {
        document.querySelector('input[name="address"]').value = data.logradouro;
        document.querySelector('input[name="city"]').value = data.localidade;
        document.querySelector('input[name="state"]').value = data.uf;
      }
    }
  });
}

function pagePedidos(){
  const user=getUser();
  console.log('Current user on orders page:', user);
  if(!user) return location.href='login.html';

  const allOrders = JSON.parse(localStorage.getItem('orders_v2')||'[]');
  console.log('All orders from localStorage:', allOrders);

  const userOrders = allOrders.filter(o => o.user && o.user.email === user.email);
  console.log('Filtered orders for this user:', userOrders);

  const tbody=document.getElementById('orders-body');
  if(!tbody) return;

  if (userOrders.length === 0) {
    tbody.innerHTML = "<tr><td colspan='5' style='text-align:center'>Você não tem nenhum pedido.</td></tr>";
  } else {
    tbody.innerHTML = userOrders.map(o=>`<tr><td>${o.id}</td><td>${new Date(o.createdAt).toLocaleString('pt-BR')}</td><td>${money(o.total)}</td><td><span class='badge'>${o.status}</span></td><td><a class='btn' href='pedido.html?id=${o.id}'>Ver Detalhes</a></td></tr>`).join('');
  }
}

// Admin: can add product with image upload (data URL)
function pageAdmin() {
  const admins = [
    { email: 'lucas123@gmail.com', password: '123' },
    { email: 'henrique123@gmail.com', password: '123' },
    { email: 'victor123@gmail.com', password: '123' }
  ];

  const loginContainer = document.getElementById('admin-login');
  const contentContainer = document.getElementById('admin-content');
  const loginForm = document.getElementById('admin-login-form');

  const loggedInAdmin = JSON.parse(sessionStorage.getItem('admin'));

  if (loggedInAdmin) {
    loginContainer.classList.add('hidden');
    contentContainer.classList.remove('hidden');
    renderAdminContent();
  } else {
    loginContainer.classList.remove('hidden');
    contentContainer.classList.add('hidden');
  }

  document.getElementById('logout-btn')?.addEventListener('click', () => {
    sessionStorage.removeItem('admin');
    loginContainer.classList.remove('hidden');
    contentContainer.classList.add('hidden');
  });

  loginForm?.addEventListener('submit', (e) => {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target).entries());
    console.log('Login form submitted with data:', data);
    const admin = admins.find(a => a.email === data.email && a.password === data.password);
    console.log('Admin found:', admin);

    if (admin) {
      console.log('Admin login successful');
      sessionStorage.setItem('admin', JSON.stringify(admin));
      loginContainer.classList.add('hidden');
      contentContainer.classList.remove('hidden');
      renderAdminContent();
    } else {
      console.log('Admin login failed');
      notify('Credenciais de admin inválidas', 'error');
    }
  });
}

function renderAdminContent() {
  const s = loadStore();
  const tbody = document.getElementById('admin-body');
  if (!tbody) return;
  tbody.innerHTML = s.products.map(p => `<tr>
    <td>${p.id}</td>
    <td>${p.name}</td>
    <td>${p.brand}</td>
    <td>${money(p.price)}</td>
    <td>
      <button class='btn small' onclick="editProd('${p.id}')">Editar</button>
      <button class='btn small danger' onclick="delProd('${p.id}')">Excluir</button>
    </td>
  </tr>`).join('');
  document.getElementById('form-add')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target).entries());
    let img = 'assets/p1.svg';
    if (data.imageUrl) {
      img = data.imageUrl;
    } else if (e.target.image && e.target.image.files && e.target.image.files[0]) {
      img = await readFileAsDataURL(e.target.image.files[0]);
    }
    const id = 'p' + (Math.floor(Math.random() * 90000) + 1000);
    const np = { id, name: data.name, brand: data.brand, category: data.category || 'Geral', price: parseFloat(data.price || 0), oldPrice: parseFloat(data.oldPrice || data.price || 0), rating: 4.5, image: img, description: data.description || '' };
    s.products.push(np);
    saveStore(s);
    notify('Produto adicionado');
    renderAdminContent();
    e.target.reset();
  });
  document.getElementById('import-btn')?.addEventListener('click', () => {
    localStorage.setItem(STORE_KEY, JSON.stringify(DEFAULT));
    saveStore(DEFAULT);
    notify('Produtos restaurados ao padrão');
    renderAdminContent();
  });
}

function readFileAsDataURL(file) { return new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(file); }); }

function delProd(id){ const s=loadStore(); s.products = s.products.filter(x=>x.id!==id); saveStore(s); notify('Produto removido'); pageAdmin(); }

// Auth
function pageLogin(){
  const form=document.getElementById('login-form');
  const reg=document.getElementById('register-form');
  const params=new URLSearchParams(location.search);
  const next=params.get('next')||'index.html';
  
  const loginCard = document.getElementById('login-card');
  const registerCard = document.getElementById('register-card');
  const showRegister = document.getElementById('show-register');
  const showLogin = document.getElementById('show-login');

  showRegister?.addEventListener('click', (e) => {
    e.preventDefault();
    loginCard.classList.add('hidden');
    registerCard.classList.remove('hidden');
  });

  showLogin?.addEventListener('click', (e) => {
    e.preventDefault();
    registerCard.classList.add('hidden');
    loginCard.classList.remove('hidden');
  });

  form?.addEventListener('submit', (e)=>{
    e.preventDefault();
    const data=Object.fromEntries(new FormData(form).entries());
    const users=JSON.parse(localStorage.getItem('users_v2')||'[]');
    const u=users.find(x=>x.email===data.email && x.password===data.password);
    if(u){
      setUser({name:u.name,email:u.email});
      notify('Bem-vindo');
      setTimeout(()=>location.href=next,400);
    }else notify('Credenciais inválidas','error');
  });
  
  reg?.addEventListener('submit', (e)=>{
    e.preventDefault();
    const data=Object.fromEntries(new FormData(reg).entries());
    let users=JSON.parse(localStorage.getItem('users_v2')||'[]');
    if(users.some(x=>x.email===data.email)) return notify('E-mail já cadastrado','error');
    users.push({name:data.name,email:data.email,password:data.password});
    localStorage.setItem('users_v2',JSON.stringify(users));
    notify('Cadastro realizado');
    reg.reset();
  });
  
  document.getElementById('logout')?.addEventListener('click', ()=>{
    setUser(null);
    notify('Logout');
    renderHeader();
  });
}

// Utilities
function notify(msg,type='success'){ const el=document.createElement('div'); el.className='alert '+(type==='error'?'error':'success'); el.textContent=msg; Object.assign(el.style,{position:'fixed',right:'18px',bottom:'18px',zIndex:120}); document.body.appendChild(el); setTimeout(()=>el.remove(),2200); renderHeader(); }
function openSearch(q){ location.hash = q ? '#'+q : ''; pageIndex(); }

function pageConta() {
  const user = getUser();
  if (!user) return location.href = 'login.html?next=conta.html';

  const profileForm = document.getElementById('profile-form');
  if (profileForm) {
    profileForm.elements.name.value = user.name;
    profileForm.elements.email.value = user.email;
  }

  const tabButtons = document.querySelectorAll('[data-tab]');
  tabButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      tabButtons.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const tab = btn.getAttribute('data-tab');
      document.getElementById('profile-tab').classList.toggle('hidden', tab !== 'profile');
      document.getElementById('address-tab').classList.toggle('hidden', tab !== 'address');
      document.getElementById('password-tab').classList.toggle('hidden', tab !== 'password');
      document.getElementById('orders-tab').classList.toggle('hidden', tab !== 'orders');
    });
  });
}

function pagePedido() {
  const user = getUser();
  if (!user) return location.href = 'login.html';

  const params = new URLSearchParams(location.search);
  const id = params.get('id');
  const orders = JSON.parse(localStorage.getItem('orders_v2') || '[]');
  const order = orders.find(o => o.id === id && o.user.email === user.email);

  if (!order) {
    document.getElementById('order-details').innerHTML = '<p>Pedido não encontrado.</p>';
    return;
  }

  const s = loadStore();
  const itemsHtml = order.items.map(item => {
    const p = s.products.find(x => x.id === item.id);
    return `<div class='flex' style='justify-content:space-between'><div>${p.name} × ${item.qty}</div><div><strong>${money(p.price * item.qty)}</strong></div></div>`;
  }).join('');

  document.getElementById('order-details').innerHTML = `
    <p><strong>Pedido:</strong> ${order.id}</p>
    <p><strong>Data:</strong> ${new Date(order.createdAt).toLocaleString('pt-BR')}</p>
    <p><strong>Status:</strong> <span class="badge">${order.status}</span></p>
    <h4 style="margin-top:18px">Itens</h4>
    <div style="display:flex;flex-direction:column;gap:8px">${itemsHtml}</div>
    <div style="margin-top:12px;display:flex;justify-content:space-between;font-size:18px">
      <strong>Total</strong><strong>${money(order.total)}</strong>
    </div>
  `;
}

// Router-like init
document.addEventListener('DOMContentLoaded', ()=>{
  init();
  const route=document.body.getAttribute('data-page');
  if(route==='index') pageIndex();
  if(route==='produto') pageProduto();
  if(route==='carrinho') pageCarrinho();
  if(route==='checkout') pageCheckout();
  if(route==='pedidos') pagePedidos();
  if(route==='admin') pageAdmin();
  if(route==='login') pageLogin();
  if(route==='conta') pageConta();
  if(route==='pedido') pagePedido();
  document.getElementById('q')?.addEventListener('input', debounce((e)=>{ location.hash = e.target.value; pageIndex(); }, 300));
});

function validateCPF(cpf) {
  cpf = cpf.replace(/[^\d]+/g,'');
  if(cpf == '') return false;
  if (cpf.length != 11 || /^(\d)\1+$/.test(cpf)) return false;
  let add = 0;
  for (let i=0; i < 9; i ++) add += parseInt(cpf.charAt(i)) * (10 - i);
  let rev = 11 - (add % 11);
  if (rev == 10 || rev == 11) rev = 0;
  if (rev != parseInt(cpf.charAt(9))) return false;
  add = 0;
  for (let i = 0; i < 10; i ++) add += parseInt(cpf.charAt(i)) * (11 - i);
  rev = 11 - (add % 11);
  if (rev == 10 || rev == 11) rev = 0;
  if (rev != parseInt(cpf.charAt(10))) return false;
  return true;
}

function validateCardNumber(number) {
  let s = 0;
  let double = false;
  for (let i = number.length - 1; i >= 0; i--) {
    let digit = parseInt(number[i]);
    if (double) {
      digit *= 2;
      if (digit > 9) digit -= 9;
    }
    s += digit;
    double = !double;
  }
  return (s % 10) == 0;
}

function debounce(fn, ms){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); } }
