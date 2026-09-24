// Automatic semicolon insertion, restricted productions and the other
// places where deleting a line break changes what a program means.
var out = []

var a = 1
var b = a
out.push(a, b)

function f() {
  return
  42
}
out.push(typeof f())

var i = 0
var j = i
++j
out.push(i, j)

var x = 5; x
--
x
out.push(x)

var d = 10
/2/1
out.push(d)

var o = { a: 1,
  b: 2 }
out.push(JSON.stringify(o))

if (true) /x/.test('x') && out.push('regex after if')
out.push(typeof /re/)

var k = 0
outer: for (k = 0; k < 3; k++) {
  for (;;) {
    continue outer
  }
}
out.push(k)

var arrow = (p) =>
  p * 2
out.push(arrow(3))

class C {
  static s = 1
  b = 2;
  ['c'] = 3;
  *gen() { yield 1; yield 2 }
  get g() { return 4 }
  h
  i = 5
}
var c = new C()
out.push(C.s, c.b, c.c, [...c.gen()].join(), c.g, 'h' in c, c.i)

var n = 1 .toString()
var m = 1.5.toFixed(1)
out.push(n, m)

var q = a
  ? 'yes'
  : 'no'
out.push(q)

var u = +  +a
var v = a - -b
var w = a + ++b
var z = a - --b
out.push(u, v, w, z, b)

var t = a ? .5 : 1
out.push(t)

var chain = o ?. a
out.push(chain)

var s1 = 'x // not a comment'   // a real comment
var s2 = "y /* not a comment */"  /* a real one */
out.push(s1, s2)

out.push("</script>".length)

function g() {
  var r = 0
  r
  ++
  r
  return r
}
out.push(g())

async function af() {
  await
  null
  return 1
}

function* gen2() {
  yield
  1
}
out.push([...gen2()].length)

af().then(function (r) {
  out.push(r)
  console.log(JSON.stringify(out))
})
