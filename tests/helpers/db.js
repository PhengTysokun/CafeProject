const mysql = require('mysql2/promise');
require('dotenv').config({ path: require('path').join(__dirname, '../.env') });

async function deleteOrder(orderId) {
  if (!orderId) throw new Error('deleteOrder requires a valid orderId');
  const conn = await mysql.createConnection({
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER,
    password: process.env.DB_PASS,
    database: process.env.DB_NAME || 'db_coffee',
  });
  try {
    await conn.beginTransaction();
    await conn.execute('DELETE FROM order_payments WHERE order_id = ?', [orderId]);
    await conn.execute('DELETE FROM order_items WHERE order_id = ?', [orderId]);
    await conn.execute('DELETE FROM orders WHERE order_id = ?', [orderId]);
    await conn.commit();
  } catch (error) {
    await conn.rollback();
    throw error;
  } finally {
    await conn.end();
  }
}

module.exports = { deleteOrder };
