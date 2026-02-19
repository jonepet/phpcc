// expect: 45
#include <vector>

int main() {
    vector<int>* v = new vector<int>();

    int i = 0;
    while (i < 10) {
        v->push_back(i);
        i = i + 1;
    }

    if (v->size() != 10) {
        return 1;
    }

    int sum = 0;
    i = 0;
    while (i < 10) {
        sum = sum + v->get(i);
        i = i + 1;
    }

    delete v;
    return sum;
}
