// Conway's Game of Life — compiled from scratch by cppc
//
// Features exercised: functions, new[], pointer array indexing,
// nested while loops, if/else, short-circuit ||, char/string output.

void __cppc_print_char(char c);
void __cppc_print_int(int n);
void __cppc_print_string(const char* s);

int neighbors(int* g, int w, int h, int x, int y) {
    int n = 0;
    int dy = -1;
    while (dy <= 1) {
        int dx = -1;
        while (dx <= 1) {
            if (dx != 0 || dy != 0) {
                int nx = x + dx;
                int ny = y + dy;
                if (nx >= 0) { if (nx < w) { if (ny >= 0) { if (ny < h) {
                    n = n + g[ny * w + nx];
                } } } }
            }
            dx = dx + 1;
        }
        dy = dy + 1;
    }
    return n;
}

void step(int* src, int* dst, int w, int h) {
    int y = 0;
    while (y < h) {
        int x = 0;
        while (x < w) {
            int n = neighbors(src, w, h, x, y);
            int alive = src[y * w + x];
            if (alive == 1) {
                if (n == 2 || n == 3) {
                    dst[y * w + x] = 1;
                } else {
                    dst[y * w + x] = 0;
                }
            } else {
                if (n == 3) {
                    dst[y * w + x] = 1;
                } else {
                    dst[y * w + x] = 0;
                }
            }
            x = x + 1;
        }
        y = y + 1;
    }
}

void draw(int* g, int w, int h, int gen) {
    __cppc_print_string("--- Generation ");
    __cppc_print_int(gen);
    __cppc_print_string(" ---");
    __cppc_print_char(10);

    int y = 0;
    while (y < h) {
        int x = 0;
        while (x < w) {
            if (g[y * w + x] == 1) {
                __cppc_print_char('#');
            } else {
                __cppc_print_char('.');
            }
            x = x + 1;
        }
        __cppc_print_char(10);
        y = y + 1;
    }
    __cppc_print_char(10);
}

int population(int* g, int sz) {
    int n = 0;
    int i = 0;
    while (i < sz) {
        n = n + g[i];
        i = i + 1;
    }
    return n;
}

int main() {
    int w = 30;
    int h = 20;
    int sz = w * h;

    int* a = new int[sz];
    int* b = new int[sz];

    // clear
    int i = 0;
    while (i < sz) {
        a[i] = 0;
        b[i] = 0;
        i = i + 1;
    }

    // Glider at (1,1) — moves down-right
    a[1 * w + 2] = 1;
    a[2 * w + 3] = 1;
    a[3 * w + 1] = 1;
    a[3 * w + 2] = 1;
    a[3 * w + 3] = 1;

    // R-pentomino at (14,8) — classic chaotic seed
    a[ 8 * w + 15] = 1;
    a[ 8 * w + 16] = 1;
    a[ 9 * w + 14] = 1;
    a[ 9 * w + 15] = 1;
    a[10 * w + 15] = 1;

    // Blinker at (24,2) — period-2 oscillator
    a[2 * w + 24] = 1;
    a[2 * w + 25] = 1;
    a[2 * w + 26] = 1;

    int gen = 0;
    while (gen <= 30) {
        draw(a, w, h, gen);
        step(a, b, w, h);

        // swap buffers
        int* tmp = a;
        a = b;
        b = tmp;

        gen = gen + 1;
    }

    int pop = population(a, sz);
    __cppc_print_string("Final population: ");
    __cppc_print_int(pop);
    __cppc_print_char(10);

    return pop % 256;
}
